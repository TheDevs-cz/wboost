<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Palette;

use Ramsey\Uuid\UuidInterface;

/**
 * "Odebrat z manuálu" (F7 P3): drops one added colour (a `manual_palette_color`
 * link) from the manual; the palette colour stays. Logo colours are refused.
 */
readonly final class RemoveManualPaletteColor
{
    public function __construct(
        public UuidInterface $manualId,
        public UuidInterface $linkId,
    ) {
    }
}
