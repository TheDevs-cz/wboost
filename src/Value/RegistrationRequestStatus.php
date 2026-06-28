<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

enum RegistrationRequestStatus: string
{
    case Pending = 'pending';
    case Invited = 'invited';
    case Dismissed = 'dismissed';
}
