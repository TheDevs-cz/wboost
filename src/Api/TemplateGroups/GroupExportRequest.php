<?php

declare(strict_types=1);

namespace WBoost\Web\Api\TemplateGroups;

final class GroupExportRequest
{
    /**
     * The ONE fill of the whole group — the same map as the single-variant
     * export's `inputs` (inputId → string | `{value, hide, fontFamily}` |
     * `{runs, lines, hide, fontFamily}`). Group members share their input ids,
     * so every dimension receives the same values; an id a dimension does not
     * carry is skipped there.
     *
     * @var array<string, mixed>
     */
    public array $inputs = [];

    /**
     * The picture picks shared by every dimension (imageInputId → gallery
     * image id | `{imageId, hide}`). Placement belongs in `placements`.
     *
     * @var array<string, mixed>
     */
    public array $images = [];

    /**
     * Per-dimension image placement: variantId → imageInputId →
     * `{scale, offsetXRatio, offsetYRatio, rotation}` (or px `offsetX`/
     * `offsetY`). A dimension's entry REPLACES the transform of the shared
     * pick for that slot; it is ignored for slots without a picture.
     *
     * @var array<string, mixed>
     */
    public array $placements = [];

    /**
     * Shape recolouring shared by every dimension (shape inputId →
     * `"#rrggbb"`) — the single-variant export's `shapeColors`. Members share
     * their shape ids; each dimension validates against its own shape.
     *
     * @var array<string, mixed>
     */
    public array $shapeColors = [];

    /**
     * Per-dimension element moves: variantId → element id → `{"x", "y"}`
     * (canvas px of THAT dimension, relative to the designed position) — each
     * size moves on its own, like `placements`; every dimension's
     * `movableElements[].range` is its own (the designer's limits are scaled
     * per format). Validated strictly per dimension like the single-variant
     * export's `offsets` (400s carry `variantId`), for every member in the
     * map — also those a single-dimension call does not render, because the
     * recorded version keeps them all. Keys that are no member of the group
     * are ignored.
     *
     * @var array<string, mixed>
     */
    public array $offsets = [];

    /**
     * Repeatable container rows, shared by every dimension: the
     * single-variant export's `rows` (row container id → list of row objects
     * keyed by the template row's member ids). The count is ONE for the whole
     * group — each dimension renders the same rows, and the SMALLEST
     * dimension decides whether they fit (`container_overflow` with that
     * dimension's `variantId`; the listing's `group.repeatableRows[].fitsRows`
     * is the designed estimate). A row id no member carries is a 400
     * `not_repeatable`. A dimension's `placements` may address a row's
     * picture by its slot id (`repeatableRows[].slots[].ids`).
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
