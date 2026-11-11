<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use WBoost\Web\Exceptions\CalendarChangeForbidden;
use WBoost\Web\Exceptions\InvalidCalendarChange;
use WBoost\Web\Mcp\Fill\CalendarLoader;
use WBoost\Web\Mcp\Response\UploadCalendarAssetResponse;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpToolScope;
use WBoost\Web\Repository\CalendarAssetRepository;
use WBoost\Web\Services\Calendar\CalendarAccess;
use WBoost\Web\Services\Calendar\CalendarAssetUploader;
use WBoost\Web\Services\Calendar\CalendarDocument;
use WBoost\Web\Value\Calendar\CalendarAssetKind;

/**
 * `upload_calendar_asset` — a file for ONE calendar: a week's photo, the
 * logo, an icon, a QR code, a header / background picture, the graphic of a
 * special page. Details: docs/architecture/calendars.md § MCP.
 *
 * ## The web's upload, not a second one
 *
 * The bytes are staged as a file and handed to
 * {@see CalendarAssetUploader::storeFile()} — the method both web routes end
 * in — so every rule is the web's: the 10 MB cap, the client storage cap, a
 * photo allow-listed by its SNIFFED type before anything decodes it, the
 * 60 MP limit, HEIC → JPEG with the rotation baked in, a PDF's first page
 * rasterised for `page`, the preview derivative. The role is the web's too
 * ({@see CalendarAccess::forUser()}): a client uploads photos only.
 *
 * ## base64 only, never a URL
 *
 * For the reason `upload_image` spells out at length: fetching an address a
 * caller chose, from inside the network that hosts Gotenberg, Minio, Redis and
 * Postgres, is a server-side request forgery.
 *
 * ## A calendar asset is not a gallery image
 *
 * Calendar files live with their calendar (`calendars/{calendarId}/…`), are
 * referenced by asset id from that calendar's JSON only, and go when the
 * calendar goes. `upload_image` (scope `gallery:write`) is a different library
 * with a different scope; its ids mean nothing here.
 */
#[McpToolScope(McpScope::CalendarsWrite)]
readonly final class UploadCalendarAssetTool
{
    public function __construct(
        private CalendarLoader $loader,
        private CalendarAccess $access,
        private CalendarAssetUploader $uploader,
        private CalendarAssetRepository $assetRepository,
        private CalendarDocument $document,
    ) {
    }

    /**
     * Uploads a file to ONE desk calendar and returns its assetId. Uploading
     * changes nothing by itself — reference the id afterwards:
     * - kind "photo": the photo of a week page → update_calendar_content
     *   {"collection": "pagePhotos", "id": "<page key>", "value": {"asset":
     *   "<assetId>", "zoom": 1, "px": 0.5, "py": 0.5}}. JPEG, PNG or WebP (a
     *   phone HEIC / HEIF / AVIF becomes a JPEG); never SVG. For print aim at
     *   ≥ 2400 px on the long edge — render_calendar_page warns below 200 dpi.
     * - kind "logo": set_calendar_layout styles {"logo": {"asset": "<id>"}}.
     * - kind "icon": an event's icon (events.icon), a waste kind's icon
     *   (styles.waste.kinds.<id>.icon), the event / time / season / moon icons
     *   (styles.icons.ev.asset, styles.icons.time.asset,
     *   styles.icons.season.asset(s), styles.moon.icons).
     * - kind "qr": styles {"qr": {"asset": "<id>"}} for every page, or
     *   pageQr {"asset": "<id>"} for one page.
     * - kind "image": a header or background picture — a fill's asset
     *   (styles.bg.asset / styles.head.bg.asset / … with imageOn: true), or one
     *   page's own pageImages {"head": "<id>"|null, "bg": "<id>"|null}.
     * - kind "page": the graphic of an uploaded special page (specialPages
     *   item, kind "image", asset "<id>"). An image, or a PDF — its first page
     *   is rasterised.
     * PNG, JPEG, WebP and SVG are accepted for every kind but photo (no SVG).
     *
     * The file is sent INLINE as base64 in fileBase64 (a data: URI is
     * tolerated). URLs are never fetched — download the file yourself and send
     * the bytes. Keep it under about 3 MB: base64 makes it a third longer and
     * one MCP request body is capped at 4 MiB, so a larger file fails at the
     * transport with a bare HTTP 413; wboost's own limit is 10 MB.
     *
     * A "client" role (project only shared with the account) may upload kind
     * "photo" only. Files are never replaced or deleted: every call adds one.
     *
     * Returns assetId, the stored size (width / height in px, null for SVG),
     * url, previewUrl, the caption embedded in the file (description) and next
     * — where to reference the id. Errors end with a reason code:
     * invalid_calendar_id, calendar_not_found, invalid_kind, invalid_file,
     * file_too_large, upload_refused (the web's rule, quoted in Czech),
     * designer_only.
     *
     * @param string $calendarId UUID of the calendar (list_calendars calendars[].id).
     * @param string $kind photo | logo | icon | qr | image | page
     * @param string $fileBase64 The file's bytes, base64-encoded (a data: URI is accepted). Not a URL — nothing is fetched.
     * @param string $filename A name for the file, e.g. "tyden-05.jpg" — shown in the web's photo list.
     */
    #[McpTool(name: 'upload_calendar_asset')]
    public function __invoke(
        string $calendarId,
        #[Schema(enum: ['photo', 'logo', 'icon', 'qr', 'image', 'page'])]
        string $kind,
        string $fileBase64,
        string $filename,
    ): UploadCalendarAssetResponse {
        $calendar = $this->loader->viewable($calendarId);
        $actor = $this->access->forUser($calendar);
        $assetKind = CalendarAssetKind::tryFrom(trim($kind));

        if ($assetKind === null) {
            throw new ToolCallException(sprintf(
                'kind must be one of: %s. (invalid_kind)',
                implode(', ', array_map(static fn (CalendarAssetKind $case): string => $case->value, CalendarAssetKind::cases())),
            ));
        }

        $bytes = self::decode($fileBase64);

        if (strlen($bytes) > CalendarDocument::MAX_FILE_SIZE) {
            throw new ToolCallException(sprintf(
                'That file is %d bytes, over the %d-byte limit (10 MB) of every wboost upload. Over MCP stay under about %d bytes — base64 makes the payload a third longer and one request body may not exceed 4 MiB. Nothing was uploaded. (file_too_large)',
                strlen($bytes),
                CalendarDocument::MAX_FILE_SIZE,
                UploadImageTool::TRANSPORT_LIMIT_BYTES,
            ));
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'wboost-mcp-calendar-');

        if ($temporaryPath === false || file_put_contents($temporaryPath, $bytes) === false) {
            if ($temporaryPath !== false) {
                @unlink($temporaryPath);
            }

            throw new ToolCallException('The file could not be staged for upload on the server. Nothing was uploaded; try again.');
        }

        try {
            // `test: true` = "a local file, not a PHP multipart upload" — the truth here.
            $assetId = $this->uploader->storeFile($calendar, $actor, $assetKind, new UploadedFile($temporaryPath, self::clientName($filename), null, null, true));
        } catch (\Throwable $failure) {
            if ($failure instanceof HandlerFailedException && $failure->getPrevious() !== null) {
                $failure = $failure->getPrevious();
            }

            if ($failure instanceof InvalidCalendarChange) {
                throw new ToolCallException(sprintf('The calendar refused the file: "%s" Nothing was uploaded. (upload_refused)', $failure->getMessage()));
            }

            if ($failure instanceof CalendarChangeForbidden) {
                throw new ToolCallException('This account is a client of the calendar (the project is only shared with it), and a client may upload photos only (kind "photo"). Nothing was uploaded. (designer_only)');
            }

            throw $failure;
        } finally {
            @unlink($temporaryPath);
        }

        $info = $this->document->asset($this->assetRepository->get($assetId));

        return new UploadCalendarAssetResponse(
            calendarId: $calendar->id->toString(),
            assetId: $assetId->toString(),
            kind: $info['kind'],
            url: $info['url'],
            previewUrl: $info['previewUrl'],
            width: $info['width'],
            height: $info['height'],
            mime: $info['mime'],
            name: $info['name'],
            description: $info['description'],
            next: match ($assetKind) {
                CalendarAssetKind::Photo => 'update_calendar_content: {"collection": "pagePhotos", "id": "<page key>", "value": {"asset": "<assetId>", "zoom": 1, "px": 0.5, "py": 0.5}}',
                CalendarAssetKind::Logo => 'set_calendar_layout: styles {"logo": {"asset": "<assetId>"}}',
                CalendarAssetKind::Icon => 'update_calendar_content events.icon, or set_calendar_layout styles (waste.kinds.<id>.icon, icons.ev.asset, icons.time.asset, icons.season.asset, moon.icons)',
                CalendarAssetKind::Qr => 'set_calendar_layout: styles {"qr": {"asset": "<assetId>"}} — or update_calendar_content pageQr for one page; the feature "qr" must be on',
                CalendarAssetKind::Image => 'set_calendar_layout: a fill — styles {"bg": {"imageOn": true, "asset": "<assetId>"}} (also head.bg, month.bg, cap.bg) — or update_calendar_content pageImages {"head": …, "bg": …} for one page',
                CalendarAssetKind::Page => 'update_calendar_content: a specialPages item of kind "image" with asset "<assetId>"; the feature "specialPages" must be on',
            },
        );
    }

    /**
     * The caller's base64 as bytes — tolerant of a data: URI header, of
     * whitespace and of missing padding; a URL is named for what it is.
     */
    private static function decode(string $value): string
    {
        $payload = trim($value);

        if (stripos($payload, 'data:') === 0) {
            $comma = strpos($payload, ',');
            $payload = $comma === false ? '' : substr($payload, $comma + 1);
        }

        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $payload) === 1) {
            throw new ToolCallException('fileBase64 takes the file\'s bytes, base64-encoded — not a URL. wboost never fetches a link a caller supplies; download the file yourself and send the bytes. (invalid_file)');
        }

        $payload = (string) preg_replace('~\s+~', '', $payload);
        $remainder = strlen($payload) % 4;

        if ($remainder !== 0) {
            $payload .= str_repeat('=', 4 - $remainder);
        }

        $bytes = $payload === '' ? false : base64_decode($payload, true);

        if ($bytes === false || $bytes === '') {
            throw new ToolCallException('fileBase64 is empty or not valid base64. Send the file\'s raw bytes encoded as base64 — not a URL, not a file path, not JSON around the data. (invalid_file)');
        }

        return $bytes;
    }

    private static function clientName(string $filename): string
    {
        $name = basename(str_replace('\\', '/', trim($filename)));

        return $name !== '' ? $name : 'soubor';
    }
}
