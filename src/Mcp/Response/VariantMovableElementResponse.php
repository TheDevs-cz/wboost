<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * An element the END USER may move ("Uživatel může posunout") — the MCP
 * twin of the REST listing's `movableElements[]`
 * ({@see \WBoost\Web\Api\Templates\TemplateVariantMovableElementResponse},
 * field for field; DescribeVariantTest compares the two).
 *
 * Move it by passing `offsets: {"<id>": {"x": …, "y": …}}` to
 * render_variant / export_variant (export_document: per page) — canvas px
 * from the designed position, +x right, +y down.
 *
 * - `kind` — `text` (locked ones too), `shape`, `image` (decorative) or
 *   `container` (a whole TOP-LEVEL container, moving with every member in
 *   `memberIds`; members are never moved on their own).
 * - `frame` — the DESIGNED bounding box (canvas px). It does not include
 *   the offset.
 * - `vertical` / `horizontal` — the allowed axes.
 * - `limits` — the designer's per-direction maximum (`up` / `down` /
 *   `left` / `right`, canvas px): null = up to the artwork edge.
 * - `range` — the EFFECTIVE offset range the server validates against
 *   (`minX`/`minY` ≤ 0 ≤ `maxX`/`maxY`, a disabled axis is 0…0): stay
 *   inside it and no call answers `offset_out_of_range`.
 */
readonly final class VariantMovableElementResponse
{
    /**
     * @param array{up: null|float, down: null|float, left: null|float, right: null|float} $limits
     * @param array{minX: float, maxX: float, minY: float, maxY: float} $range
     * @param list<string> $memberIds
     */
    public function __construct(
        public string $id,
        public string $kind,
        public null|string $name,
        public VariantInputFrameResponse $frame,
        public bool $vertical,
        public bool $horizontal,
        public array $limits,
        public array $range,
        public array $memberIds = [],
    ) {
    }
}
