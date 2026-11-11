<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Entity\CalendarHistoryEntry;
use WBoost\Web\Mcp\Fill\CalendarLoader;
use WBoost\Web\Mcp\Response\CalendarChangeResponse;
use WBoost\Web\Mcp\Response\GetCalendarResponse;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpToolScope;
use WBoost\Web\Repository\CalendarHistoryRepository;
use WBoost\Web\Services\Calendar\CalendarAccess;
use WBoost\Web\Services\Calendar\CalendarContentRules;
use WBoost\Web\Services\Calendar\CalendarDocument;

/**
 * `get_calendar` — one calendar as an agent needs it: settings, the whole
 * content document, the uploaded files, the newest history lines. Details:
 * docs/architecture/calendars.md § MCP.
 *
 * The body is {@see CalendarDocument} — the very document the web workspace
 * boots from — minus two things: `layout.styles` (the look, owned by the
 * calendar JavaScript and not changeable through MCP) and everything that
 * belongs to the browser session (CSRF token, endpoints, the client link:
 * its token is a secret that grants anonymous write access, so it is never
 * handed to an agent).
 *
 * The server does not generate pages (the browser does), so this tool cannot
 * list them; the docblock below teaches the page-key rule instead.
 */
#[McpToolScope(McpScope::CalendarsRead)]
readonly final class GetCalendarTool
{
    /** History lines included in the answer. */
    public const int HISTORY_LIMIT = 20;

    public function __construct(
        private CalendarLoader $loader,
        private CalendarAccess $access,
        private CalendarDocument $document,
        private CalendarHistoryRepository $historyRepository,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Reads one desk calendar (list_calendars calendars[].id): its settings,
     * ALL of its content, the files uploaded to it and the newest lines of its
     * change history. Call it before update_calendar_content — an update
     * replaces whole items, so you need the stored item to change one field.
     *
     * A calendar has two halves. The DESIGN (name, year, type, features, print,
     * page size, layout) belongs to the designer and is read-only here. The
     * CONTENT is what the client fills in; it is `content`:
     * {"v": 1, "<collection>": {"<id>": item}} — collections of individually
     * addressable items (shapes: see update_calendar_content). A collection
     * that was never written is simply absent; treat it as empty.
     *
     * Reading the settings:
     * - year, type ("week" | "fortnight"): which pages exist. Pages are
     *   generated in the browser, not stored. Week pages run from the Monday of
     *   the week containing 1 January to the week containing 31 December; a
     *   fortnight calendar takes every second one. A page is addressed by a
     *   PAGE KEY that does not contain the year: "wNN" = the page whose first
     *   Monday has ISO week NN of the calendar year ("w05"), "pre" = a first
     *   page whose Monday still belongs to the previous ISO year (e.g. the page
     *   starting 28 Dec 2026 in a 2027 calendar), "post" = a page whose Monday
     *   belongs to the next ISO year, plus "cover", "back" and "sp:<id>" for a
     *   special page. In a fortnight calendar only every second week starts a
     *   page (2027: pre, w02, w04 … w52).
     * - features: which kinds of content are PRINTED (events, waste, moon,
     *   shifts, specialPages, qr, captions…). Items of a switched-off feature
     *   are stored and kept, but do not show until the designer switches the
     *   feature on — tell the user rather than assume it is visible. `{}` means
     *   never saved: captions, weekNumbers and moon are on, the rest off.
     * - layout: {boxes, order} in mm from the top-left trim corner, null until
     *   the designer chooses a layout. The styles are not included.
     * - role: "designer" or "client" — what this account is for this calendar;
     *   writableCollections lists what update_calendar_content will accept.
     *
     * assets: {"<assetId>": {url, previewUrl, width, height, mime, name,
     * description, kind}} — the uploaded files content items refer to by id
     * (pagePhotos.asset, pageQr.asset, events.icon…). Files cannot be uploaded
     * through this connector; only ids listed here can be referenced.
     *
     * history: the newest change lines (who: "designer" | "client", what: a
     * Czech sentence, at), newest first. revision grows by one with every save.
     *
     * Dates everywhere are "YYYY-MM-DD". This tool changes nothing.
     *
     * @param string $calendarId UUID of the calendar, from list_calendars calendars[].id.
     */
    #[McpTool(name: 'get_calendar')]
    public function __invoke(string $calendarId): GetCalendarResponse
    {
        $calendar = $this->loader->viewable($calendarId);
        $actor = $this->access->forUser($calendar);
        $document = $this->document->calendar($calendar);

        /** @var \stdClass $content */
        $content = $document['content'];

        return new GetCalendarResponse(
            id: $calendar->id->toString(),
            projectId: $calendar->project->id->toString(),
            projectName: $calendar->project->name,
            name: $calendar->name,
            year: $calendar->year,
            type: $calendar->type->value,
            finished: $calendar->finished,
            revision: $calendar->revision,
            updatedAt: $calendar->updatedAt->format(\DATE_ATOM),
            role: $actor->kind->value,
            writableCollections: $actor->isDesigner() ? CalendarContentRules::COLLECTIONS : CalendarContentRules::CLIENT_WRITABLE,
            preset: $calendar->layoutPreset,
            features: (object) $calendar->features,
            print: (object) $calendar->print,
            page: (object) $calendar->page,
            layout: self::layoutSummary($calendar),
            content: $content,
            assets: $this->document->assets($calendar),
            history: array_map(
                static fn (CalendarHistoryEntry $entry): CalendarChangeResponse => new CalendarChangeResponse(
                    who: $entry->authorKind->value,
                    what: $entry->description,
                    at: $entry->createdAt->format(\DATE_ATOM),
                ),
                $this->historyRepository->latest($calendar->id, self::HISTORY_LIMIT),
            ),
            url: $this->urlGenerator->generate('calendar_workspace', ['calendarId' => $calendar->id->toString()], UrlGeneratorInterface::ABSOLUTE_URL),
        );
    }

    /**
     * The layout without its styles: where the elements sit and how they
     * stack. Built as a NEW object — the entity's tree is never touched.
     */
    private static function layoutSummary(Calendar $calendar): null|\stdClass
    {
        $layout = $calendar->layout;

        if ($layout === null) {
            return null;
        }

        $summary = new \stdClass();
        $summary->boxes = $layout->boxes ?? new \stdClass();
        $summary->order = $layout->order ?? [];

        return $summary;
    }
}
