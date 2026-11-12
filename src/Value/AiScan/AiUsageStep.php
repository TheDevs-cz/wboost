<?php

declare(strict_types=1);

namespace WBoost\Web\Value\AiScan;

enum AiUsageStep: string
{
    case PreCheck = 'pre_check';
    case Builder = 'builder';
}
