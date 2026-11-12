<?php

declare(strict_types=1);

namespace WBoost\Web\Value\AiScan;

/** Stage codes (architecture.md §5.6). */
enum AiScanStage: string
{
    case Check = 'check';
    case Read = 'read';
    case Analyse = 'analyse';
    case Build = 'build';
    case Refine = 'refine';
    case Finish = 'finish';
}
