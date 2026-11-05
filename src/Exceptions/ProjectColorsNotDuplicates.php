<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

/**
 * "Sloučit" (F7 P4) merges only palette colours of ONE project with the same
 * HEX (`hex_key`) — anything else (another HEX, another project, the survivor
 * itself, nothing to merge) is refused.
 */
final class ProjectColorsNotDuplicates extends \Exception
{
}
