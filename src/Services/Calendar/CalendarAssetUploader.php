<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use WBoost\Web\Controller\Calendar\UploadCalendarAssetController;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Exceptions\CalendarChangeForbidden;
use WBoost\Web\Exceptions\InvalidCalendarChange;
use WBoost\Web\Message\Calendar\UploadCalendarAsset;
use WBoost\Web\Repository\CalendarAssetRepository;
use WBoost\Web\Services\Image\DownscaleImage;
use WBoost\Web\Services\Image\ImageDecodeLimits;
use WBoost\Web\Services\Image\NormalizeImageFormat;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Value\Calendar\CalendarActor;
use WBoost\Web\Value\Calendar\CalendarAssetKind;
use WBoost\Web\Value\RenderImageFormat;

/**
 * The upload endpoint behind both routes — `/calendar/{id}/asset` and the
 * client link's `/k/{token}/asset` (docs/calendar/architecture.md §C.7):
 * multipart `_token`, `kind`, `file`; answers the new asset's info. The
 * calendar document is not touched: the client references the id and saves.
 */
readonly final class CalendarAssetUploader
{
    /** Rasters with a longer edge get a preview derivative of this size. */
    public const int PREVIEW_LONG_EDGE = 1600;

    /**
     * What clients (the link holders and read-only members together) may
     * store in ONE calendar, summed over `calendar_asset.size_bytes`. The link
     * needs no account, so without a total it is free storage at the pace the
     * rate limits allow. A year of photos is 55 × ≤ 10 MB; 2 GB leaves room
     * for replacing every one of them three times.
     */
    public const int MAX_CLIENT_BYTES = 2_000_000_000;

    /**
     * What a `photo` may be, as sniffed from its bytes: the three formats the
     * message names, and what phones send instead of a JPEG (stored as JPEG
     * by {@see NormalizeImageFormat}).
     */
    public const array PHOTO_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/webp',
        'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence', 'image/avif',
    ];

    public const string TOO_LARGE = 'Soubor je větší než 10 MB.';
    public const string TOO_MANY_PIXELS = 'Obrázek má příliš velké rozměry (nejvýše 60 megapixelů). Zmenšete ho a nahrajte znovu.';
    public const string CLIENT_STORAGE_FULL = 'Do tohoto kalendáře už bylo nahráno příliš mnoho fotek (limit 2 GB). Obraťte se prosím na grafika.';
    public const string PHOTO_FORMAT = 'Fotka musí být JPG, PNG nebo WebP.';
    public const string UNREADABLE = 'Tento formát obrázku neumíme přečíst. Nahrajte PNG, JPG, WebP nebo SVG.';

    public function __construct(
        private MessageBusInterface $bus,
        private ValidatorInterface $validator,
        private NormalizeImageFormat $normalizeImageFormat,
        private DownscaleImage $downscaleImage,
        private ReadImageDescription $readImageDescription,
        private RasterizePdfPage $rasterizePdfPage,
        private CalendarAssetRepository $assetRepository,
        private CalendarDocument $document,
        private ProvideIdentity $provideIdentity,
        private CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    public function upload(Request $request, Calendar $calendar, CalendarActor $actor): JsonResponse
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(CalendarDocument::CSRF_TOKEN_ID, $request->request->getString('_token')))) {
            return CalendarResponses::csrfExpired();
        }

        return CalendarResponses::guard(fn (): JsonResponse => $this->store($request, $calendar, $actor));
    }

    /**
     * @throws InvalidCalendarChange
     * @throws CalendarChangeForbidden
     */
    private function store(Request $request, Calendar $calendar, CalendarActor $actor): JsonResponse
    {
        $kind = CalendarAssetKind::tryFrom($request->request->getString('kind'));

        if ($kind === null) {
            throw new InvalidCalendarChange('Neznámý druh souboru.');
        }

        if (!$actor->isDesigner() && !$kind->clientMayUpload()) {
            throw new CalendarChangeForbidden();
        }

        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return CalendarResponses::error('Vyberte soubor.', Response::HTTP_BAD_REQUEST);
        }

        $assetId = $this->storeFile($calendar, $actor, $kind, $file);

        return new JsonResponse(['id' => $assetId->toString()] + $this->document->asset($this->assetRepository->get($assetId)));
    }

    /**
     * Stores one file as an asset of the calendar and answers its id — the
     * whole upload rule set (role, size, client storage cap, format sniffing,
     * pixel cap, normalisation, preview). Both web routes and the MCP tool
     * `upload_calendar_asset` end here.
     *
     * @throws InvalidCalendarChange
     * @throws CalendarChangeForbidden
     */
    public function storeFile(Calendar $calendar, CalendarActor $actor, CalendarAssetKind $kind, UploadedFile $file): UuidInterface
    {
        if (!$actor->isDesigner() && !$kind->clientMayUpload()) {
            throw new CalendarChangeForbidden();
        }

        if ($file->getSize() > CalendarDocument::MAX_FILE_SIZE) {
            throw new InvalidCalendarChange(self::TOO_LARGE);
        }

        if (!$actor->isDesigner() && $this->assetRepository->clientBytes($calendar->id) + (int) $file->getSize() > self::MAX_CLIENT_BYTES) {
            throw new InvalidCalendarChange(self::CLIENT_STORAGE_FULL);
        }

        ImageDecodeLimits::apply();

        $original = $file->getContent();
        $contents = $original;

        // A photo — the one kind the public link may send — is one of a few
        // raster formats, judged by its BYTES before anything looks closer at
        // it. Everything else is refused here: an SVG however it is padded or
        // named, XML, GIF, and the formats ImageMagick would hand to
        // Inkscape or Ghostscript (MVG, EPS…).
        if ($kind === CalendarAssetKind::Photo && !in_array((new \finfo(FILEINFO_MIME_TYPE))->buffer($original), self::PHOTO_MIME_TYPES, true)) {
            throw new InvalidCalendarChange(self::PHOTO_FORMAT);
        }

        $isPdf = $kind === CalendarAssetKind::Page && RasterizePdfPage::isPdf($original);

        if ($isPdf) {
            $contents = $this->rasterizePdfPage->firstPage($original);
        } else {
            $violations = $this->validator->validate($file, new Image(maxSize: UploadCalendarAssetController::MAX_SIZE));

            if (count($violations) > 0) {
                throw new InvalidCalendarChange((string) $violations->get(0)->getMessage());
            }
        }

        // The size the header announces, before any decode (a 10 MB PNG can
        // announce 30 000 × 30 000).
        $announced = ImageDecodeLimits::pixelSize($contents);

        if ($announced !== null && ImageDecodeLimits::exceeds($announced[0], $announced[1])) {
            throw new InvalidCalendarChange(self::TOO_MANY_PIXELS);
        }

        $normalized = $this->normalizeImageFormat->normalize($contents);
        $width = null;
        $height = null;
        $preview = null;
        $previewExtension = null;

        if ($normalized !== null) {
            $contents = $normalized['contents'];
            $extension = $normalized['extension'];
            $mimeType = $normalized['mimeType'];
            $width = $normalized['width'];
            $height = $normalized['height'];

            if (max($width, $height) > self::PREVIEW_LONG_EDGE) {
                // WebP keeps the transparency of a logo or an icon; a JPEG
                // source has none and stays JPEG-sized as WebP too.
                $downscaled = $this->downscaleImage->toLongEdge($contents, self::PREVIEW_LONG_EDGE, RenderImageFormat::Webp);

                if ($downscaled['downscaled']) {
                    $preview = $downscaled['contents'];
                    $previewExtension = RenderImageFormat::Webp->value;
                }
            }
        } elseif (NormalizeImageFormat::looksLikeSvg($contents)) {
            if ($kind === CalendarAssetKind::Photo) {
                throw new InvalidCalendarChange(self::PHOTO_FORMAT);
            }

            $extension = 'svg';
            $mimeType = 'image/svg+xml';
        } else {
            throw new InvalidCalendarChange(self::UNREADABLE);
        }

        $assetId = $this->provideIdentity->next();
        $base = sprintf('calendars/%s/%s', $calendar->id->toString(), $assetId->toString());

        $this->bus->dispatch(new UploadCalendarAsset(
            $assetId,
            $calendar->id,
            $kind,
            sprintf('%s.%s', $base, $extension),
            $contents,
            $preview !== null ? sprintf('%s-preview.%s', $base, $previewExtension) : null,
            $preview,
            $mimeType,
            self::fileName($file->getClientOriginalName()),
            $width,
            $height,
            $normalized !== null && !$isPdf ? $this->readImageDescription->read($original) : null,
            $actor,
        ));

        return $assetId;
    }

    private static function fileName(string $name): string
    {
        // The browser's name for the file: valid UTF-8 and no control
        // character before it goes into a text column.
        $name = trim(basename(str_replace('\\', '/', CalendarText::singleLine($name))));

        return $name !== '' ? mb_substr($name, 0, 255) : 'soubor';
    }
}
