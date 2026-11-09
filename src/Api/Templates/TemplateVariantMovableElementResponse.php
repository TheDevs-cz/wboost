<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Templates;

/**
 * An element the END USER may move ("Uživatel může posunout"): a text
 * (locked ones too), a shape, a decorative image, or a whole TOP-LEVEL
 * container (`kind: "container"` — it moves as one block with every member,
 * `memberIds`). Move it by sending `offsets: {"<id>": {"x": …, "y": …}}`
 * on preview / export — canvas px relative to the designed position,
 * +x right, +y down (per dimension on a group, per page on a document).
 *
 * - `frame` — the DESIGNED axis-aligned bounding box (canvas px, the same
 *   space as `inputs[].frame`; a container's = the union of its visible
 *   members). It does not include the offset: add it yourself when drawing.
 * - `vertical` / `horizontal` — the allowed axes.
 * - `limits` — the designer's per-direction maximum in canvas px
 *   (`up` / `down` / `left` / `right`): null = unlimited, but the element
 *   stays on the artwork (inside the trim for print); 0 = not that way.
 *   A disabled axis carries nulls.
 * - `range` — the EFFECTIVE offset range the server validates against
 *   (`minX`/`minY` ≤ 0 ≤ `maxX`/`maxY`, a disabled axis is 0…0): clamp to
 *   it client-side and an export never answers `offset_out_of_range`.
 */
final readonly class TemplateVariantMovableElementResponse
{
    /**
     * @param 'text'|'shape'|'image'|'container'|string $kind
     * @param array{up: null|float, down: null|float, left: null|float, right: null|float} $limits
     * @param array{minX: float, maxX: float, minY: float, maxY: float} $range
     * @param list<string> $memberIds a container's deep member ids; [] for the other kinds
     */
    public function __construct(
        public string $id,
        public string $kind,
        public null|string $name,
        public TemplateVariantInputFrameResponse $frame,
        public bool $vertical,
        public bool $horizontal,
        public array $limits,
        public array $range,
        public array $memberIds = [],
    ) {
    }
}
