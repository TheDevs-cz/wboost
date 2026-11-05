<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

/**
 * A manual's logo colour cannot be removed from the manual — it comes from
 * the logo and leaves only with a new one (F7 P3, D3).
 */
final class ManualPaletteColorFromLogo extends \Exception
{
}
