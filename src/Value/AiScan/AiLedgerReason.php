<?php

declare(strict_types=1);

namespace WBoost\Web\Value\AiScan;

enum AiLedgerReason: string
{
    case Reserve = 'reserve';
    case Settle = 'settle';
    case Refund = 'refund';
    case Grant = 'grant';
    case Adjust = 'adjust';
}
