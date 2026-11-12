<?php

declare(strict_types=1);

namespace WBoost\Web\Value\AiScan;

enum AiScanKind: string
{
    case Template = 'template';
    case Calendar = 'calendar';
}
