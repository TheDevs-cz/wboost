<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Ramsey\Uuid\Uuid;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Message\Manual\UploadManualContentImage;
use WBoost\Web\Services\Image\NormalizeImageFormat;
use WBoost\Web\Services\Security\ManualVoter;
use WBoost\Web\Services\UploaderHelper;

/**
 * Uploads ONE picture for a content page image element and answers its key
 * + public URL; the page references it only once the editor saves (an
 * upload that is never saved is an orphan the storage scan reports).
 *
 * The cap is the app-wide 10 MB DECIMAL (`maxSize: '10m'`), mirrored by the
 * editor (`data-…-max-file-size-value="10000000"`) — see
 * docs/architecture/gallery-and-uploads.md. Pictures are normalised like the
 * gallery's (HEIC → JPEG/PNG); SVG stays vector.
 */
final class UploadManualContentImageController extends AbstractController
{
    public const string MAX_SIZE = '10m';

    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ValidatorInterface $validator,
        readonly private NormalizeImageFormat $normalizeImageFormat,
        readonly private UploaderHelper $uploaderHelper,
    ) {
    }

    #[Route(path: '/manual/{manualId}/obsah/obrazek', name: 'manual_content_image_upload', methods: ['POST'])]
    #[IsGranted(ManualVoter::EDIT, 'manual')]
    public function __invoke(
        #[MapEntity(id: 'manualId')]
        Manual $manual,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid(ManualIntroPageController::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            return new JsonResponse(['error' => 'Platnost formuláře vypršela. Obnovte stránku.'], Response::HTTP_FORBIDDEN);
        }

        $file = $request->files->get('image');

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return new JsonResponse(['error' => 'Vyberte obrázek.'], Response::HTTP_BAD_REQUEST);
        }

        $violations = $this->validator->validate($file, new Image(maxSize: self::MAX_SIZE));

        if (count($violations) > 0) {
            return new JsonResponse(['error' => (string) $violations->get(0)->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $contents = $file->getContent();
        $normalized = $this->normalizeImageFormat->normalize($contents);
        $width = null;
        $height = null;

        if ($normalized !== null) {
            $contents = $normalized['contents'];
            $extension = $normalized['extension'];
            $width = $normalized['width'];
            $height = $normalized['height'];
        } elseif (stripos(substr($contents, 0, 1024), '<svg') !== false) {
            $extension = 'svg';
        } else {
            return new JsonResponse(['error' => 'Tento formát obrázku neumíme přečíst. Nahrajte PNG, JPG, WebP nebo SVG.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $path = sprintf('manuals/%s/content/%s.%s', $manual->id->toString(), Uuid::uuid7()->toString(), $extension);

        $this->bus->dispatch(new UploadManualContentImage($manual->id, $path, $contents));

        return new JsonResponse([
            'path' => $path,
            'url' => $this->uploaderHelper->getPublicPath($path),
            'width' => $width,
            'height' => $height,
        ]);
    }
}
