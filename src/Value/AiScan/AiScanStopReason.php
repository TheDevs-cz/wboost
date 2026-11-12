<?php

declare(strict_types=1);

namespace WBoost\Web\Value\AiScan;

/** Why the intake stopped a scan (`stopped`). */
enum AiScanStopReason: string
{
    case PhotoOfPrint = 'photo_of_print';
    case BlurryOrSmall = 'blurry_or_small';
    case NotExpectedKind = 'not_expected_kind';
    case SeveralDesigns = 'several_designs';
    case UnusableInput = 'unusable_input';

    /** Only a photo of a print may be continued ("Zkusit přesto"). */
    public function isContinuable(): bool
    {
        return $this === self::PhotoOfPrint;
    }
}
