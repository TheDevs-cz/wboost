<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Component\Validator\Constraints\When;

final class MealVariantFormData
{
    public const string MODE_MANUAL = 'manual';
    public const string MODE_REFERENCE = 'reference';

    public null|UuidInterface $id = null;

    #[NotBlank]
    public string $mode = self::MODE_REFERENCE;

    #[When(
        expression: 'this.mode === "manual"',
        constraints: [
            new NotBlank(normalizer: 'trim'),
            new Length(min: 1, max: 255),
        ],
    )]
    public string $name = '';

    #[When(
        expression: 'this.mode === "reference"',
        constraints: [new NotNull(message: 'Vyberte jídlo')],
    )]
    public null|UuidInterface $referenceMealId = null;

    /** @var array<UuidInterface> */
    public array $dietIds = [];

    #[PositiveOrZero]
    public null|string $energyValue = null;

    #[PositiveOrZero]
    public null|string $fats = null;

    #[PositiveOrZero]
    public null|string $carbohydrates = null;

    #[PositiveOrZero]
    public null|string $proteins = null;
}
