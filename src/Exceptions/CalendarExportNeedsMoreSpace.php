<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

/**
 * The work directory has no room for the PDF about to be made (its chunk
 * files plus the merged file — up to twice the size of the calendar's
 * pictures). Thrown before anything is rendered.
 */
final class CalendarExportNeedsMoreSpace extends \RuntimeException
{
    public function __construct(public readonly int $neededBytes, public readonly int $freeBytes)
    {
        parent::__construct(sprintf('The calendar PDF needs about %d MB of work space, %d MB are free.', (int) ($neededBytes / 1_000_000), (int) ($freeBytes / 1_000_000)));
    }
}
