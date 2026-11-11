<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Result\CallToolResult;
use WBoost\Web\Exceptions\CalendarModelRefused;
use WBoost\Web\Exceptions\CalendarModelUnavailable;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Mcp\Fill\CalendarLoader;
use WBoost\Web\Mcp\Fill\CalendarWriter;
use WBoost\Web\Mcp\Fill\VariantFill;
use WBoost\Web\Mcp\Response\RenderCalendarPageResponse;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpToolScope;
use WBoost\Web\Services\Calendar\CalendarPageRenderer;
use WBoost\Web\Value\RenderImageFormat;

/**
 * `render_calendar_page` — the look at one page of a calendar. Details:
 * docs/architecture/calendars.md § MCP.
 *
 * The picture is drawn by the calendar's own renderer inside headless
 * Chromium ({@see CalendarPageRenderer}) from what is STORED — the same code,
 * fonts and measuring as the web workspace, so an agent judges the real
 * result, not a server-side imitation. It only draws: nothing is saved,
 * nothing is recorded. The PDF of the whole calendar is `manage_calendar`
 * "export_pdf".
 *
 * Read scope, like `render_variant`: a client role may look too.
 *
 * The reply is two content blocks — a JSON summary, then the image — for the
 * reason `render_variant` gives: a picture cannot travel as text.
 */
#[McpToolScope(McpScope::CalendarsRead)]
readonly final class RenderCalendarPageTool
{
    private const RenderImageFormat FORMAT = RenderImageFormat::Webp;

    public function __construct(
        private CalendarLoader $loader,
        private CalendarPageRenderer $calendarPages,
    ) {
    }

    /**
     * Renders ONE page of a desk calendar as it is stored now and returns it
     * as an image — call it after every layout, style or content change to SEE
     * the result. A lossy WebP, 1600 px on its long edge (about 5 px per mm of
     * a 310 mm page): enough to read every text and judge the composition.
     *
     * page is a page key from get_calendar pages[].key: "cover", "back", a
     * week page ("w01" … "w53", "pre", "post" — in a 14-day calendar every
     * second one), or "sp:<id>" for a special page that is switched on. An
     * unknown key is refused and the valid keys are listed (unknown_page).
     *
     * Options:
     * - bleed: true — include the bleed around the trim (what the printer
     *   gets; shows whether pictures and bands really reach past the cut).
     * - marks: true — the bleed plus the crop marks.
     * - sampleTexts: true — draw the designer's sample texts where there is no
     *   content yet (a sample caption, the QR placeholder), as step 3 of the
     *   web does. By default a week without a photo shows the neutral photo
     *   placeholder and nothing invented.
     *
     * What you see is what the web shows: content of a switched-off feature is
     * not drawn, a hidden element is absent, the first week page is the one
     * holding 1 January. A calendar without a layout cannot be drawn
     * (no_layout — give it one with set_calendar_layout). If the project has
     * no fonts, texts use a built-in sans-serif that may differ slightly from
     * the web's.
     *
     * The JSON summary in front of the picture: the page (key, kind, title,
     * date range), width / height of the picture, viewMm (what it shows in mm
     * from the top-left trim corner) and pxPerMm — to locate a box from
     * layout.boxes in the image — warnings (a portrait photo, a photo under
     * 200 dpi, icons or event texts that do not fit; Czech sentences, their
     * fit estimated), and pageKeys.
     *
     * Nothing is saved. Errors end with a reason code: invalid_calendar_id,
     * calendar_not_found, no_layout, unknown_page, model_unavailable,
     * renderer_busy (try again in a few seconds), render_failed.
     *
     * @param string $calendarId UUID of the calendar (list_calendars calendars[].id).
     * @param string $page Page key: "cover", "back", "w01"…"w53", "pre", "post" or "sp:<id>" (get_calendar pages[].key).
     * @param bool $bleed Include the bleed around the trim.
     * @param bool $marks Include the bleed and the crop marks.
     * @param bool $sampleTexts Draw sample texts where content is missing (as the web's style editor does).
     */
    #[McpTool(name: 'render_calendar_page')]
    public function __invoke(
        string $calendarId,
        string $page,
        bool $bleed = false,
        bool $marks = false,
        bool $sampleTexts = false,
    ): CallToolResult {
        $calendar = $this->loader->viewable($calendarId);

        try {
            $render = $this->calendarPages->render($calendar, trim($page), $bleed, $marks, $sampleTexts, format: self::FORMAT);
        } catch (CalendarModelRefused | CalendarModelUnavailable $failure) {
            throw CalendarWriter::refusal(
                $failure instanceof CalendarModelRefused
                    ? new CalendarModelRefused($failure->reason, $failure->getMessage())
                    : $failure,
            );
        } catch (TemplateRenderUnavailable) {
            throw new ToolCallException('The image renderer is busy and did not answer in time. Nothing was changed — try the same call again in a few seconds. (renderer_busy)');
        } catch (\Throwable $failure) {
            throw new ToolCallException(sprintf('Rendering the page failed: %s (render_failed)', mb_substr($failure->getMessage(), 0, 400)));
        }

        $summary = new RenderCalendarPageResponse(
            calendarId: $calendar->id->toString(),
            calendarName: $calendar->name,
            page: $render->page['key'],
            kind: $render->page['kind'],
            title: $render->page['title'],
            subtitle: $render->page['subtitle'],
            from: $render->page['from'] ?? null,
            to: $render->page['to'] ?? null,
            format: self::FORMAT->contentType(),
            width: $render->width,
            height: $render->height,
            viewMm: $render->view,
            pxPerMm: round($render->width / $render->view['w'], 3),
            warnings: array_column($render->warnings, 'message'),
            pageKeys: array_column($render->pages, 'key'),
        );

        return new CallToolResult([
            VariantFill::summary($summary),
            ImageContent::fromString($render->bytes, self::FORMAT->contentType()),
        ]);
    }
}
