<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Entity\CalendarAsset;
use WBoost\Web\Entity\Project;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Repository\CalendarAssetRepository;
use WBoost\Web\Repository\CalendarRepository;
use WBoost\Web\Repository\ProjectColorRepository;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\Calendar\CalendarActor;
use WBoost\Web\Value\RichTextFontOption;

/**
 * The calendar as the JavaScript receives it — the bootstrap JSON of the
 * workspace / client / list pages and the answer of every save
 * (docs/calendar/architecture.md §C.4).
 *
 * Maps are emitted as JSON OBJECTS even when empty: PHP would encode an empty
 * array as `[]`, so every map (`page`, `print`, `features`, `content`, each
 * collection, `assets`) is cast to an object first. `layout` already is an
 * object tree ({@see \WBoost\Web\Doctrine\JsonObjectDoctrineType}).
 */
readonly final class CalendarDocument
{
    public const string CSRF_TOKEN_ID = 'calendar';

    /** JS mirror of {@see \WBoost\Web\Controller\Calendar\UploadCalendarAssetController::MAX_SIZE} (10 MB decimal). */
    public const int MAX_FILE_SIZE = 10_000_000;

    public function __construct(
        private CalendarRepository $calendarRepository,
        private CalendarAssetRepository $assetRepository,
        private GetFonts $getFonts,
        private ProjectColorRepository $projectColorRepository,
        private UploaderHelper $uploaderHelper,
        private UrlGeneratorInterface $urlGenerator,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The workspace / client page bootstrap.
     *
     * @param bool $viaToken true on the public `/k/{token}` routes: the
     *   endpoints then are the token ones (a logged-in read-only member keeps
     *   the `/calendar/{id}/…` endpoints and never learns the token)
     * @return array{mode: string, calendar: array<string, mixed>, assets: \stdClass, env: array<string, mixed>}
     */
    public function forCalendar(Calendar $calendar, CalendarActor $actor, bool $viaToken = false): array
    {
        $designer = $actor->isDesigner();
        $id = ['calendarId' => $calendar->id->toString()];
        $token = ['token' => $calendar->clientToken];

        $env = $this->projectEnv($calendar->project);

        if ($viaToken) {
            $env['urls'] = [
                'save' => $this->urlGenerator->generate('calendar_client_save', $token),
                'asset' => $this->urlGenerator->generate('calendar_client_asset', $token),
                'history' => $this->urlGenerator->generate('calendar_client_history', $token),
                'export' => $this->urlGenerator->generate('calendar_client_export_request', $token),
            ];
        } else {
            $env['urls'] = [
                'list' => $this->urlGenerator->generate('calendars', ['projectId' => $calendar->project->id->toString()]),
                'save' => $this->urlGenerator->generate('calendar_save', $id),
                'asset' => $this->urlGenerator->generate('calendar_asset_upload', $id),
                'history' => $this->urlGenerator->generate('calendar_history', $id),
                'export' => $this->urlGenerator->generate('calendar_export_request', $id),
            ];
        }

        if ($designer) {
            $env['urls']['clientLink'] = $this->urlGenerator->generate('calendar_client_link', $id);
            $env['urls']['layoutImport'] = $this->urlGenerator->generate('calendar_layout_import', $id);
            $env['urls']['documentText'] = $this->urlGenerator->generate('calendar_document_text', $id);
            $env['scan'] = $this->scanEnv($calendar);
            $env['clientUrl'] = $this->clientUrl($calendar);
            $env['siblings'] = [];

            foreach ($this->calendarRepository->forProject($calendar->project->id) as $sibling) {
                if ($sibling->id->equals($calendar->id)) {
                    continue;
                }

                $env['siblings'][] = [
                    'id' => $sibling->id->toString(),
                    'name' => $sibling->name,
                    'type' => $sibling->type->value,
                    'hasLayout' => $sibling->layout !== null,
                ];
            }
        }

        if ($designer) {
            // Where the layout came from when not a preset (`scan`) — beside
            // the document, never inside it (the design's JavaScript does not know it).
            $env['layoutOrigin'] = $calendar->layoutOrigin;
        }

        return [
            'mode' => $designer ? 'designer' : 'client',
            'calendar' => $this->calendar($calendar),
            'assets' => $this->assets($calendar),
            'env' => $env,
        ];
    }

    /**
     * The addresses of „Naskenovat design“ (wizard step 2, designers only):
     * the AI scan's own endpoints, with the nil uuid where a scan id goes.
     * Whether the card is live, locked or waiting is answered by `entry`.
     *
     * @return array{nil: string, urls: array<string, string>}
     */
    private function scanEnv(Calendar $calendar): array
    {
        $nil = '00000000-0000-0000-0000-000000000000';
        $project = ['projectId' => $calendar->project->id->toString()];
        $id = $calendar->id->toString();
        $scan = ['id' => $nil];
        $own = ['calendarId' => $id, 'scanId' => $nil];

        return [
            'nil' => $nil,
            'urls' => [
                'entry' => $this->urlGenerator->generate('ai_scan_entry', $project + ['kind' => 'calendar', 'calendarId' => $id]),
                'start' => $this->urlGenerator->generate('ai_scan_start', $project),
                'accessRequest' => $this->urlGenerator->generate('ai_scan_access_request', $project),
                'history' => $this->urlGenerator->generate('ai_scan_history', $project),
                'status' => $this->urlGenerator->generate('ai_scan_status', $scan),
                'proposal' => $this->urlGenerator->generate('ai_scan_proposal', $scan),
                'image' => $this->urlGenerator->generate('ai_scan_image', $scan + ['which' => 'working']),
                'action' => $this->urlGenerator->generate('ai_scan_action', $scan + ['action' => 'cancel']),
                'seen' => $this->urlGenerator->generate('ai_scan_feedback', $scan + ['action' => 'seen']),
                'rating' => $this->urlGenerator->generate('ai_scan_feedback', $scan + ['action' => 'rating']),
                'accept' => $this->urlGenerator->generate('calendar_scan', $own + ['action' => 'accept']),
                'restore' => $this->urlGenerator->generate('calendar_scan', $own + ['action' => 'restore']),
                'picture' => $this->urlGenerator->generate('calendar_scan', $own + ['action' => 'picture']),
                'fonts' => $this->urlGenerator->generate('fonts_list', ['id' => $calendar->project->id->toString()]),
            ],
        ];
    }

    /**
     * What a save answers: the stored calendar and its assets.
     *
     * @return array{calendar: array<string, mixed>, assets: \stdClass, savedAt: string, layoutOrigin?: null|string}
     */
    public function saved(Calendar $calendar, bool $designer = true): array
    {
        $saved = [
            'calendar' => $this->calendar($calendar),
            'assets' => $this->assets($calendar),
            'savedAt' => $calendar->updatedAt->format(DATE_ATOM),
        ];

        if ($designer) {
            // Beside the document, never inside it: the marker is not part of
            // the design the calendar's JavaScript normalises. Step 2 is the
            // designer's, so a client's answer does not carry it.
            $saved['layoutOrigin'] = $calendar->layoutOrigin;
        }

        return $saved;
    }

    /**
     * What one card of the list page fetches to draw itself
     * ({@see \WBoost\Web\Controller\Calendar\CalendarCardController}).
     *
     * @return array{calendar: array<string, mixed>, assets: \stdClass}
     */
    public function card(Calendar $calendar): array
    {
        return [
            'calendar' => $this->calendar($calendar),
            'assets' => $this->assets($calendar),
        ];
    }

    /**
     * The part of `env` every calendar of a project shares — the list page
     * emits it once.
     *
     * @return array<string, mixed>
     */
    public function projectEnv(Project $project): array
    {
        return [
            'projectName' => $project->name,
            'fonts' => $this->fonts($project),
            'palette' => $this->palette($project),
            'currentYear' => $this->currentYear(),
            'maxFileSize' => self::MAX_FILE_SIZE,
            'csrf' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ];
    }

    /**
     * The project's font faces as the calendar JavaScript reads them; a text
     * style's `family` is one of these `family` strings (or null).
     *
     * @return list<array{family: string, fontName: string, faceName: string, weight: int, style: string, url: string}>
     */
    public function fonts(Project $project): array
    {
        return array_map(
            static fn (RichTextFontOption $face): array => $face->toArray(),
            ResolveRichTextOptions::projectFaces($this->getFonts->allForProject($project->id), $this->uploaderHelper),
        );
    }

    /**
     * The project's colours, `#RRGGBB` upper case, unique.
     *
     * @return list<string>
     */
    public function palette(Project $project): array
    {
        $palette = [];

        foreach ($this->projectColorRepository->forProject($project->id) as $color) {
            $hex = strtoupper($color->hexKey);

            if (preg_match('/^#[0-9A-F]{6}$/', $hex) === 1 && !in_array($hex, $palette, true)) {
                $palette[] = $hex;
            }
        }

        return $palette;
    }

    public function currentYear(): int
    {
        return (int) $this->clock->now()->format('Y');
    }

    /**
     * @return array<string, mixed>
     */
    public function calendar(Calendar $calendar): array
    {
        $content = ['v' => 1];

        foreach ($calendar->content as $key => $collection) {
            if ($key === 'v') {
                continue;
            }

            $content[$key] = is_array($collection) ? (object) $collection : $collection;
        }

        return [
            'id' => $calendar->id->toString(),
            'revision' => $calendar->revision,
            'name' => $calendar->name,
            'year' => $calendar->year,
            'type' => $calendar->type->value,
            'page' => (object) $calendar->page,
            'print' => (object) $calendar->print,
            'features' => (object) $calendar->features,
            'preset' => $calendar->layoutPreset,
            'layout' => $calendar->layout,
            'linkElements' => $calendar->linkElements,
            'step' => $calendar->step,
            'finished' => $calendar->finished,
            'content' => (object) $content,
        ];
    }

    /**
     * Asset id → info, as a JSON object.
     */
    public function assets(Calendar $calendar): \stdClass
    {
        $map = [];

        foreach ($this->assetRepository->forCalendar($calendar->id) as $asset) {
            $map[$asset->id->toString()] = $this->asset($asset);
        }

        return (object) $map;
    }

    /**
     * @return array{url: string, previewUrl: null|string, width: null|int, height: null|int, mime: string, name: string, description: null|string, kind: string}
     */
    public function asset(CalendarAsset $asset): array
    {
        return [
            'url' => $this->uploaderHelper->getPublicPath($asset->path),
            'previewUrl' => $asset->previewPath !== null ? $this->uploaderHelper->getPublicPath($asset->previewPath) : null,
            'width' => $asset->width,
            'height' => $asset->height,
            'mime' => $asset->mimeType,
            'name' => $asset->originalName,
            'description' => $asset->description,
            'kind' => $asset->kind->value,
        ];
    }

    /** The absolute client link — it addresses the calendar by its token alone. */
    public function clientUrl(Calendar $calendar): string
    {
        return $this->urlGenerator->generate('calendar_client', ['token' => $calendar->clientToken], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * JSON for a `<script type="application/json">` block: `<`, `>` and `&`
     * are escaped so no value can close the script element.
     *
     * @param array<string, mixed> $document
     */
    public static function encode(array $document): string
    {
        return json_encode(
            $document,
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
    }
}
