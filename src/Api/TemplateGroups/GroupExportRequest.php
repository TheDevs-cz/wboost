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
}
