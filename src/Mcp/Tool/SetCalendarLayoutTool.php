<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use WBoost\Web\Mcp\Fill\CalendarLoader;
use WBoost\Web\Mcp\Fill\CalendarPresenter;
use WBoost\Web\Mcp\Fill\CalendarWriter;
use WBoost\Web\Mcp\Response\CalendarDesignResponse;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpToolScope;

/**
 * `set_calendar_layout` — steps 2 and 3 of the web workspace for an agent:
 * choose a preset, then move, hide, restack and restyle. Details:
 * docs/architecture/calendars.md § MCP.
 *
 * ## The layout is never built here
 *
 * A preset's default layout, the mirroring of Fotka vlevo ↔ vpravo, the
 * clamping of every value and the auto-grow of Měsíc / Číslo týdne are the
 * calendar JavaScript's. The arguments become model commands
 * ({@see \WBoost\Web\Services\Calendar\CalendarModelBridge}); the model
 * answers the whole new layout as the save payload the workspace would send,
 * and {@see CalendarWriter} stores it through `SaveCalendar`. Which box keys
 * and style keys exist is read from the calendar's own layout at run time —
 * nothing is listed in PHP.
 *
 * ## A patch, validated before the merge
 *
 * `boxes` and `styles` name only what changes. An unknown key is REFUSED with
 * the key and its siblings in the message (`unknown_box`,
 * `unknown_style_key`) — the model would silently drop it otherwise, and an
 * agent would believe it had restyled something.
 */
#[McpToolScope(McpScope::CalendarsWrite)]
readonly final class SetCalendarLayoutTool
{
    public function __construct(
        private CalendarLoader $loader,
        private CalendarWriter $writer,
        private CalendarPresenter $presenter,
    ) {
    }

    /**
     * Gives a desk calendar its page layout and changes how it looks: where
     * the elements sit, which are hidden, how they stack, and every style
     * (fonts, sizes, colours, fills, the Kalendárium's parts, icons). One
     * layout serves ALL week pages of the calendar — pages never have their
     * own. Designer role only. Call get_calendar first: its layout is the
     * exact tree this tool patches, and fonts[] / palette are what you may use.
     * Check the result with render_calendar_page.
     *
     * Arguments (any combination; applied in this order):
     * 1. preset — "photoLeft" (photo left, days right) or "photoRight" for a
     *    7-day calendar, "twoSided" (week 1 left, photo in the middle, week 2
     *    right) for a 14-day one. On a calendar WITHOUT a layout it creates the
     *    default layout (the browser's own defaults; fonts from the project's
     *    first font, the accent from its first colour). On a calendar that has
     *    one, switching photoLeft ↔ photoRight MIRRORS the current layout and
     *    keeps its styling. reset: true instead throws the current layout away
     *    and applies the preset's untouched default.
     * 2. boxes — {"<element>": {x?, y?, w?, h?, hidden?}}: only the fields to
     *    change. Millimetres from the TOP-LEFT TRIM CORNER of the page (x →
     *    right, y → down), 0.1 mm steps; the page is 310 × 145 mm unless
     *    get_calendar page says otherwise. A box may reach into the bleed
     *    (down to −bleedMm and up to width + bleedMm) and no further; minimum
     *    6 × 4 mm. Elements: photo, cap (photo caption), logo, head (the header
     *    band), month, cal (the Kalendárium — the day rows), wk (week number),
     *    qr; a 14-day calendar also has the second-week frames listed in its
     *    layout.boxes (e.g. cal2). hidden: true / false works on logo, month,
     *    wk, qr only; the caption is hidden by the feature switch captions and
     *    wk / qr also need their feature (weekNumbers, qr) switched on.
     *    Out-of-range values are brought into range and reported in notes.
     * 3. order — the stacking order: every key of layout.order exactly once,
     *    TOP layer first (default qr, logo, cap, wk, month, head, cal, photo).
     *    The page background is always at the bottom.
     * 4. styles — a partial copy of layout.styles: nested objects are merged,
     *    scalars and lists are replaced whole. Example: {"num": {"size": 30,
     *    "color": "#1A1A1A"}, "month": {"upper": false}, "cal": {"numberSide":
     *    "start"}}.
     *
     * Units and values in styles: type sizes and line widths in pt (size 3–60),
     * lengths in mm, opacities 0–100, colours "#RRGGBB" (null only where the
     * tree shows null), family = one of get_calendar fonts[].family EXACTLY
     * (e.g. "Rubik (Rubik Bold)") or null for the built-in face (then weight
     * 100–900 applies).
     *
     * The style tree (get_calendar layout.styles is the truth; this is a map):
     * - Text styles {family, weight, size, color}: num (day number, working
     *   days), numSat, numSun, numHol (public holidays), day (weekday name),
     *   name (name day + extra names), hol (holiday name; + wrapWidth mm|null),
     *   ev (events; + align, valign, box {x, y, w, h}|null = the event box, x
     *   measured from the text column's edge), time (time change / season
     *   text; + position "text"|"sep", align, valign), shift (shift letters; +
     *   bgMode "alt"|"all"|"none", bg), placeText (waste place labels), month
     *   (+ align, upper, pad, bg fill), wk (+ align, form "a" = "1. týden" |
     *   "b" = "Týden 1" | "c" = "T1"), cap (+ align, valign, pad, bg fill),
     *   head.text.
     * - cal: numberSide "start"|"end" (day number left / right of the row),
     *   numAlign, dayFormat "full"|"short", dayPosition "side"|"above",
     *   dayAlign, satColor, sunColor, bg {color|null, opacity}, iconSize (mm
     *   1.5–12), iconGrid "4"|"2x2"|"1+2"|"2+1", iconCellW (mm|null),
     *   iconCells (per grid, per cell), placeLabels, emptyIconSlot.
     * - lines: {on, width (pt 0.1–5), style "solid"|"dash"|"dot", color} —
     *   the rules between days.
     * - Fills — bg (page background), head.bg, month.bg, cap.bg: {colorOn,
     *   color, colorOpacity, gradient {on, type "linear"|"radial", angle,
     *   stops [{color, opacity, pos 0–100}, …]}, imageOn, asset (an uploaded
     *   asset id|null), fit "cover"|"contain", imageOpacity}.
     * - photo: {source "client"|"self", radius, border {on, width, color},
     *   shadow {on, blur, opacity, dx, dy, color}}; logo: {asset, fit}; qr:
     *   {perPage, asset}.
     * - moon: {color, size (mm 1.5–9), valign, phases [new, first quarter,
     *   full, last quarter] booleans, icons [asset|null ×4]}.
     * - icons: {order — the icon order in a day row, a list out of "waste",
     *   "moon", "ev", "time", "season"; ev {name, asset, perEvent}; time
     *   {asset, color}; season {mode "four"|"one", asset, assets [×4], color}}.
     * - waste.kinds {"<wasteKindId>": {color?, icon?}} and offsets {"<part>":
     *   {x, y}} (mm nudges of num, day, name, hol, moon, ev, time, shift,
     *   waste, icon) are maps keyed by data: an entry is merged one level
     *   deep, null removes it.
     * align values are "start"|"middle"|"end" (start = left), valign
     * "top"|"middle"|"bottom"; null where the tree shows null means automatic.
     * An asset must be uploaded to THIS calendar first (upload_calendar_asset).
     *
     * The call is atomic — a refused call stores nothing. It records "Upravil
     * vzhled a nastavení" in the history. After a preset the web opens the
     * calendar in step 3 (Úpravy prvků). Month and week-number boxes grow by
     * themselves to hold their widest text; here that width is estimated
     * (no fonts on the server) and corrected the next time the calendar is
     * edited on the web.
     *
     * Returns the design as stored (layout with every style), changed, notes
     * (clamped values, a mirrored / reset layout — read them), recorded, url.
     * Errors end with a reason code: invalid_calendar_id, calendar_not_found,
     * designer_only, nothing_to_do, unknown_preset, preset_type_mismatch,
     * no_layout, unknown_box, invalid_box, not_hideable, invalid_order,
     * unknown_style_key, invalid_style_value, unknown_font, invalid_color,
     * invalid_design (the server's rule, quoted in Czech — e.g. an asset id
     * that is not uploaded to this calendar), model_unavailable (retry).
     *
     * @param string $calendarId UUID of the calendar (list_calendars calendars[].id).
     * @param null|string $preset "photoLeft" | "photoRight" (7 days) or "twoSided" (14 days). Omit to keep the layout the calendar has.
     * @param null|bool $reset With preset: true = replace the current layout by the preset's untouched default (all styling lost).
     * @param null|array<array-key, mixed> $boxes {"<element>": {x?, y?, w?, h?, hidden?}} in mm from the top-left trim corner.
     * @param null|list<string> $order Every element key once, top layer first.
     * @param null|array<array-key, mixed> $styles Partial copy of layout.styles — nested objects merge, scalars and lists replace.
     */
    #[McpTool(name: 'set_calendar_layout')]
    public function __invoke(
        string $calendarId,
        #[Schema(enum: ['photoLeft', 'photoRight', 'twoSided'])]
        null|string $preset = null,
        null|bool $reset = null,
        #[Schema(type: 'object', additionalProperties: true)]
        null|array $boxes = null,
        #[Schema(type: 'array', items: ['type' => 'string'])]
        null|array $order = null,
        #[Schema(type: 'object', additionalProperties: true)]
        null|array $styles = null,
    ): CalendarDesignResponse {
        $calendar = $this->loader->viewable($calendarId);
        $actor = $this->writer->designer($calendar);

        $commands = [];

        if ($preset !== null && trim($preset) !== '') {
            $commands[] = ['op' => 'preset', 'preset' => $preset, 'reset' => $reset === true];
        }

        $patch = ['op' => 'layout'];

        if ($boxes !== null && $boxes !== []) {
            $patch['boxes'] = array_is_list($boxes) ? $boxes : (object) $boxes;
        }

        if ($order !== null && $order !== []) {
            $patch['order'] = $order;
        }

        if ($styles !== null && $styles !== []) {
            $patch['styles'] = array_is_list($styles) ? $styles : (object) $styles;
        }

        if (count($patch) > 1) {
            $commands[] = $patch;
        }

        if ($commands === []) {
            throw new ToolCallException('Nothing to do: pass a preset and / or boxes, order, styles. (nothing_to_do)');
        }

        $applied = $this->writer->apply($calendar, $actor, $commands);

        return $this->presenter->design($calendar, $applied['model'], $applied['changed'], $applied['model']->notes, $applied['recorded']);
    }
}
