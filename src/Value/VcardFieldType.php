<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

enum VcardFieldType: string
{
    case Name = 'name';
    case Email = 'email';
    case Phone = 'phone';
    case Company = 'company';
    case JobTitle = 'jobTitle';
    case Website = 'website';
}
