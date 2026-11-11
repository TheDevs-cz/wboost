<?php

declare(strict_types=1);

namespace WBoost\Web\Value\ManualContent;

/**
 * A gradient background's shape: linear (direction = angle) or radial
 * (a circle growing from {@see ContentGradientCenter}).
 */
enum ContentGradientShape: string
{
    case Linear = 'linear';
    case Radial = 'radial';
}
