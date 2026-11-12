<?php

declare(strict_types=1);

namespace WBoost\Web\Value\AiScan;

enum ProviderState: string
{
    case Ok = 'ok';
    case Blocked = 'blocked';
}
