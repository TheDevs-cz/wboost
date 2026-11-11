<?php

declare(strict_types=1);

namespace WBoost\Web\Value\ManualContent;

/**
 * What a content page background is ("Pozadí:" Barva / Přechod / Obrázek;
 * "Žádné" = no background = null). Rows stored before 2026-11-11 carry no
 * `type` and are pictures.
 */
enum ContentBackgroundKind: string
{
    case Color = 'color';
    case Gradient = 'gradient';
    case Image = 'image';
}
