<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Palette;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\ProjectColorField;

/**
 * One autosaved field of a palette colour card (F7 P2). The edit reaches
 * every manual linking the colour (option C).
 */
readonly final class EditProjectColor
{
    public function __construct(
        public UuidInterface $colorId,
        public ProjectColorField $field,
        /** hex / name / role (`primary`|`secondary`|null) / pantone / ral, normalised by `PaletteColorInput`. */
        public null|string $value,
        /** @var null|array{null|string, null|string, null|string, null|string} for ProjectColorField::Cmyk */
        public null|array $cmyk = null,
    ) {
    }
}
