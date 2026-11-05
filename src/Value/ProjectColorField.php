<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * One autosaved field of a palette colour card on "Paleta barev" (F7 P2) —
 * the `field` the card posts to `project_palette_color_edit`.
 */
enum ProjectColorField: string
{
    case Hex = 'hex';
    case Name = 'name';
    case Role = 'role';
    case Cmyk = 'cmyk';
    case Pantone = 'pantone';
    case Ral = 'ral';
}
