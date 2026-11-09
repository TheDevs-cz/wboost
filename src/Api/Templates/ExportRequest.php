<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Templates;

final class ExportRequest
{
    /**
     * Map of inputId UUID → value: a plain string, `{ value, hide, fontFamily }`,
     * or — for inputs with `richText: true` — `{ runs: [...], hide, fontFamily }`
     * where each run is `{ "text": "...", "fontFamily": null|string,
     * "color": null|"#rrggbb", "underline": bool }` (null style = inherit the
     * designed style). Fonts — a run's and the whole-text `fontFamily` alike —
     * must come from that input's `fontOptions[].family`; `fontFamily` on the
     * value switches the WHOLE textbox (the user's font choice) and is only
     * accepted for inputs that list `fontOptions`.
     * Inputs whose ids are missing keep the variant's default canvas text.
     * Locked inputs cannot be addressed and are always served from the canvas
     * defaults. Discover ids via `GET /api/projects/{projectId}/templates`
     * (`variants[].inputs[].id`). Unknown ids are silently ignored.
     *
     * @var array<string, mixed>
     */
    public array $inputs = [];

    /**
     * Map of imageInputId UUID → chosen gallery image + optional placement.
     * Each value is either a plain **string** (the gallery image id, placed
     * centered + object-contain in the designer's frame) or an object
     * `{ "imageId": "...", "scale": 1, "offsetX": 0, "offsetY": 0, "rotation": 0 }`
     * (`scale` multiplies the contain-fit, `offsetX/Y` pan in canvas px from the
     * frame centre, `rotation` is degrees), or `{ "hide": true }` to blank a
     * hidable slot.
     *
     * The pan may instead be given as `offsetXRatio` / `offsetYRatio` — the same
     * pan as a FRACTION of the frame's width / height (0.25 = a quarter of the
     * frame to the right / down). That form is portable: the same value keeps
     * one crop intent when it is reused on another variant of the same template
     * whose frame differs. Send one form or the other per axis; both for the
     * same axis is a 400.
     *
     * Discover the ids, frames and allowed folders via
     * `GET /api/projects/{projectId}/templates`
     * (`variants[].imageInputs[]`); list a slot's pickable images via
     * `GET /api/template-variants/{variantId}/placeholders/{inputId}/images`.
     * Unfilled slots keep the designer's stand-in image. An adjustment a slot
     * does not permit (move / resize / rotate) is rejected with 400.
     *
     * @var array<string, mixed>
     */
    public array $images = [];

    /**
     * Map of shape inputId UUID → `"#rrggbb"`: repaint a shape the designer
     * opened up for recolouring (`variants[].shapeInputs[]`) — its fill, its
     * border or both, as the shape's `target` says. Without `freeColor` the
     * colour must be one of the shape's `colorOptions` (400
     * `shape_color_not_allowed` with `allowedColors`); anything that is not a
     * hex colour is a 400 `invalid_shape_color`. Both carry `shapeInputId`.
     * An empty string or an omitted id keeps the designed colour; unknown ids
     * are silently ignored.
     *
     * @var array<string, mixed>
     */
    public array $shapeColors = [];

    /**
     * Map of element id → `{"x": number, "y": number}`: the user's moves of
     * the elements the designer allows to move (`variants[].movableElements[]`
     * — texts, shapes, decorative images, whole top-level containers), in
     * canvas px relative to the designed position (+x right, +y down). An
     * axis left out is 0; `{0, 0}` (or an omitted id) keeps the designed
     * position. Validated STRICTLY: an id that is no element of the variant
     * is a 400 `unknown_element`; an element (or axis) that may not move a
     * 400 `move_not_allowed` (`reason`: `not_allowed` | `in_container` |
     * `hidden`, `axis` when only that axis is refused); a value past the
     * element's `range` a 400 `offset_out_of_range` (`axis`, `min`, `max`,
     * `value`); a malformed entry a 400 `invalid_value`. All carry
     * `elementId`. A moved top-level container takes part in the container
     * reflow — it may push the containers below it or overflow the canvas
     * (400 `container_overflow`).
     *
     * @var array<string, mixed>
     */
    public array $offsets = [];

    /**
     * Repeatable container rows ("Opakovatelný řádek"): row container id →
     * a LIST of row objects, one per row, in order — the list length IS the
     * row count (`repeatableRows[].repeat.min` … `max`). Each row object is
     * keyed by the TEMPLATE row's member ids (`repeatableRows[].members[].id`)
     * and takes the value shapes of the maps above: a text → what `inputs`
     * takes (string | `{value, hide, fontFamily}` | `{runs, lines, …}`), an
     * image slot → what `images` takes (`imageId` | `{imageId, scale, …}` |
     * `{hide: true}`), a recolourable shape → `"#rrggbb"`, a hidable
     * container of the row → `{hide: true}`. A member left out of a row
     * object shows the template row's sample. Without `rows` every row shows
     * its designed default count.
     *
     * Strict: an id that is no repeatable row → 400 `not_repeatable`; a list
     * outside `[min, max]` → `too_few_rows` / `too_many_rows` (`count`, `min`,
     * `max`); an unknown key in a row object → `unknown_row_input`
     * (`rowIndex`, `inputId`); a member of a sent row ALSO addressed in
     * `inputs` / `images` / `shapeColors` (row 1's own ids included) →
     * `ambiguous_row_value`; a malformed `rows` → `invalid_value`. All carry
     * `containerId`. A refused VALUE inside a row answers its usual code with
     * `rowContainerId` + `rowIndex` (0-based) and the member id you sent. Rows
     * that do not fit the parent container's height answer the usual
     * `container_overflow` (on the root container).
     */
    public mixed $rows = [];

    /**
     * `"png"` (default — the lossless image) or `"pdf"`: the print PDF —
     * PDF/X-1a:2001, CMYK (Coated FOGRA39), the variant's bleed + crop marks.
     * PDF exists only for print formats (`variants[].printFormat`); a screen
     * format answers 400 `pdf_not_available`, any other value 400
     * `invalid_format`.
     */
    public null|string $format = null;
}
