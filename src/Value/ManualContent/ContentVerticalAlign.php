<?php

declare(strict_types=1);

namespace WBoost\Web\Value\ManualContent;

/**
 * Where a segment's elements sit when its grid cell is taller than they are
 * (the rows grow with the tallest segment).
 */
enum ContentVerticalAlign: string
{
    case Top = 'top';
    case Center = 'center';
    case Bottom = 'bottom';
}
