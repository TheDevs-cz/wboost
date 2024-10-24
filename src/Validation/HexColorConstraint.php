<?php

declare(strict_types=1);

namespace WBoost\Web\Validation;

use Attribute;
use Symfony\Component\Validator\Constraint;

#[Attribute]
final class HexColorConstraint extends Constraint
{
    public string $message = 'Hodnota {{ value }} není validní HEX kód barvy.';
}
