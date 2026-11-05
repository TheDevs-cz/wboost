<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Palette;

use Ramsey\Uuid\UuidInterface;

/**
 * Drag reorder / "Posunout výš / níž" of a manual's colours (F7 P3) — the
 * manual colour order `color(n)` indexes (logo recolouring defaults). Logo and
 * added colours each reorder only among their own places.
 */
readonly final class SortManualPaletteColors
{
    public function __construct(
        public UuidInterface $manualId,
        /** @var array<string> link ids (`manual_palette_color`), new order */
        public array $linkIds,
    ) {
    }
}
