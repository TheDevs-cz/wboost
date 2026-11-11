<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use WBoost\Web\Entity\CalendarHistoryEntry;
use WBoost\Web\Exceptions\CalendarModelRefused;
use WBoost\Web\Exceptions\CalendarModelUnavailable;
use WBoost\Web\Mcp\Fill\CalendarLoader;
use WBoost\Web\Mcp\Response\CalendarChangeResponse;
use WBoost\Web\Mcp\Response\GetCalendarResponse;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpToolScope;
use WBoost\Web\Repository\CalendarHistoryRepository;
use WBoost\Web\Services\Calendar\CalendarAccess;
use WBoost\Web\Services\Calendar\CalendarContentRules;
use WBoost\Web\Services\Calendar\CalendarDocument;
use WBoost\Web\Services\Calendar\CalendarHistoryList;
use WBoost\Web\Services\Calendar\CalendarModelBridge;

/**
 * `get_calendar` — one calendar as an agent needs it: settings, the layout
 * with every style, the whole content document, the page list, the uploaded
 * files, the project's fonts and colours, the newest history lines. Details:
 * docs/architecture/calendars.md § MCP.
 *
 * The body is {@see CalendarDocument} — the very document the web workspace
 * boots from — minus everything that belongs to the browser session (CSRF
 * token, endpoints) and minus the client link: its token grants anonymous
 * write access, so a READ scope never sees it (`manage_calendar`
 * "client_link", write scope + designer role, hands it out).
 *
 * ## Materialised by the calendar model
 *
 * The server stores `{}` for never-saved settings and knows no default; the
 * JavaScript fills them in. So this tool runs the model
 * ({@see CalendarModelBridge}) and answers the design AS THE BROWSER SEES IT
 * — every feature switch, the print settings, the page size, the layout with
 * every style key (the exact tree `set_calendar_layout` patches) — and the
 * generated page list. When the model cannot be run the stored values are
 * answered instead and `pages` is null; reading never fails over it.
 */
#[McpToolScope(McpScope::CalendarsRead)]
readonly final class GetCalendarTool
{
    /** History lines included by default / at most (the web's Historie shows 200). */
    public const int HISTORY_LIMIT = 20;
    public const int MAX_HISTORY_LIMIT = CalendarHistoryList::LIMIT;

    public function __construct(
        private CalendarLoader $loader,
        private CalendarAccess $access,
        private CalendarDocument $document,
        private CalendarModelBridge $model,
        private CalendarHistoryRepository $historyRepository,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Reads one desk calendar (list_calendars calendars[].id): its settings,
     * its layout and styles, ALL of its content, its pages, the files uploaded
     * to it and the newest lines of its change history. Call it before any
     * calendar write — content updates replace whole items and layout changes
     * patch the tree shown here.
     *
     * A calendar has two halves. The DESIGN (name, year, type, features, print,
     * layout) belongs to the designer: manage_calendar changes the settings,
     * set_calendar_layout the layout. The CONTENT is `content`:
     * {"v": 1, "<collection>": {"<id>": item}} — collections of individually
     * addressable items (shapes: see update_calendar_content). A collection
     * that was never written is simply absent; treat it as empty.
     *
     * Reading the answer:
     * - year, type ("week" = 7 days per page | "fortnight" = 14 days per page).
     * - pages: every page of the calendar in print order — {index, key, kind:
     *   "cover"|"special"|"week"|"back", title, subtitle, from, to (first and
     *   last date of a week page)}. `key` is the PAGE KEY that page-keyed
     *   content (pagePhotos, pageCaptions, pageImages, pageQr) and
     *   render_calendar_page use. Keys do not contain the year: "wNN" = the
     *   page whose first Monday has ISO week NN, "pre" = a first page whose
     *   Monday still belongs to the previous ISO year (e.g. 28 Dec 2026 in a
     *   2027 calendar), "post" = a last page whose Monday belongs to the next
     *   one, "cover", "back", "sp:<id>" = a special page. A 14-day calendar
     *   has every second week (2027: pre, w02, w04 … w52). null only when the
     *   page list could not be computed right now.
     * - features: the ten switches (captions, weekNumbers, moon, timeChange,
     *   seasons, waste, events, shifts, specialPages, qr). Content of a
     *   switched-off feature is stored but NOT printed — switch it on with
     *   manage_calendar "configure".
     * - print {bleed, bleedMm, marks}, page {width, height, bandHeight, holes}
     *   in mm.
     * - preset + layout: null until a layout is chosen (set_calendar_layout
     *   with a preset). layout = {v, boxes, order, styles}: boxes in mm from
     *   the top-left trim corner, order = stacking (top first), styles = every
     *   style value — documented in set_calendar_layout.
     * - fonts: the project's font faces; a text style's family is one of
     *   fonts[].family exactly, or null. palette: the project's colours.
     * - role: "designer" or "client" — what this account is for this calendar;
     *   writableCollections lists what update_calendar_content will accept
     *   from it. A client cannot change the design.
     * - finished: the designer marked the look as done; step: the web
     *   workspace step it opens in (1 Typ … 5 Náhled).
     *
     * assets: {"<assetId>": {url, previewUrl, width, height, mime, name,
     * description, kind}} — the files uploaded to THIS calendar; content items
     * and styles refer to them by id (pagePhotos.asset, events.icon,
     * styles.logo.asset…). Upload new ones with upload_calendar_asset.
     *
     * history: the newest change lines (who: "designer" | "client", what: a
     * Czech sentence, at), newest first — historyLimit of them (default 20, at
     * most 200). revision grows by one with every save.
     *
     * Dates everywhere are "YYYY-MM-DD". This tool changes nothing.
     *
     * @param string $calendarId UUID of the calendar, from list_calendars calendars[].id.
     * @param null|int $historyLimit How many history lines to return, 0–200 (default 20).
     */
    #[McpTool(name: 'get_calendar')]
    public function __invoke(string $calendarId, null|int $historyLimit = null): GetCalendarResponse
    {
        $calendar = $this->loader->viewable($calendarId);
        $actor = $this->access->forUser($calendar);
        $document = $this->document->calendar($calendar);

        /** @var \stdClass $content */
        $content = $document['content'];

        try {
            $model = $this->model->run($calendar, pages: true);
            $design = $model->design;
            $pages = $model->pages;
        } catch (CalendarModelUnavailable | CalendarModelRefused) {
            $design = null;
            $pages = null;
        }

        $limit = max(0, min(self::MAX_HISTORY_LIMIT, $historyLimit ?? self::HISTORY_LIMIT));

        return new GetCalendarResponse(
            id: $calendar->id->toString(),
            projectId: $calendar->project->id->toString(),
            projectName: $calendar->project->name,
            name: $calendar->name,
            year: $calendar->year,
            type: $calendar->type->value,
            finished: $calendar->finished,
            step: $calendar->step,
            revision: $calendar->revision,
            updatedAt: $calendar->updatedAt->format(\DATE_ATOM),
            role: $actor->kind->value,
            writableCollections: $actor->isDesigner() ? CalendarContentRules::COLLECTIONS : CalendarContentRules::CLIENT_WRITABLE,
            preset: $calendar->layoutPreset,
            features: self::materialised($design, 'features') ?? (object) $calendar->features,
            print: self::materialised($design, 'print') ?? (object) $calendar->print,
            page: self::materialised($design, 'page') ?? (object) $calendar->page,
            layout: $calendar->layout === null ? null : (self::materialised($design, 'layout') ?? $calendar->layout),
            pages: $pages,
            content: $content,
            assets: $this->document->assets($calendar),
            fonts: array_map(
                static fn (array $face): array => ['family' => $face['family'], 'fontName' => $face['fontName'], 'faceName' => $face['faceName'], 'weight' => $face['weight'], 'style' => $face['style']],
                $this->document->fonts($calendar->project),
            ),
            palette: $this->document->palette($calendar->project),
            history: $limit === 0 ? [] : array_map(
                static fn (CalendarHistoryEntry $entry): CalendarChangeResponse => new CalendarChangeResponse(
                    who: $entry->authorKind->value,
                    what: $entry->description,
                    at: $entry->createdAt->format(\DATE_ATOM),
                ),
                $this->historyRepository->latest($calendar->id, $limit),
            ),
            url: $this->urlGenerator->generate('calendar_workspace', ['calendarId' => $calendar->id->toString()], UrlGeneratorInterface::ABSOLUTE_URL),
        );
    }

    /**
     * One map of the model's materialised design; null when the model did not run.
     */
    private static function materialised(null|\stdClass $design, string $key): null|\stdClass
    {
        $value = $design === null ? null : ($design->{$key} ?? null);

        return $value instanceof \stdClass ? $value : null;
    }
}
