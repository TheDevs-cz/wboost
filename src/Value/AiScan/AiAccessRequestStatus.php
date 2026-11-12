<?php

declare(strict_types=1);

namespace WBoost\Web\Value\AiScan;

enum AiAccessRequestStatus: string
{
    case Open = 'open';
    case Approved = 'approved';
    case Dismissed = 'dismissed';
}
