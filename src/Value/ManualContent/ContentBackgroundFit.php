<?php

declare(strict_types=1);

namespace WBoost\Web\Value\ManualContent;

/**
 * How a content page's background picture fills the page (always anchored
 * centre top, never repeated):
 * - cover ("Vyplnit", default): fills the whole page, cropping the overflow;
 * - contain ("Celý obrázek"): the whole picture, the rest of the page stays white.
 */
enum ContentBackgroundFit: string
{
    case Cover = 'cover';
    case Contain = 'contain';
}
