<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Palette;

use Ramsey\Uuid\UuidInterface;

/**
 * "Přidat barvu" on Paleta barev (F7 P2): a new palette colour at the end of
 * the palette — no role, no manual until one picks it (P3).
 */
readonly final class AddProjectColor
{
    public function __construct(
        public UuidInterface $colorId,
        public UuidInterface $projectId,
        /** Validated by `PaletteColorInput::hex()`, without `#`. */
        public string $hex,
    ) {
    }
}
