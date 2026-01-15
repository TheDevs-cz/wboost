<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Component\Validator\Constraints\Valid;
use WBoost\Web\Value\WeeklyMenuMealType;

final class MealFormData
{
    #[NotBlank(normalizer: 'trim')]
    #[Length(min: 2, max: 255)]
    public string $name = '';

    #[NotBlank(normalizer: 'trim')]
    #[Length(min: 2, max: 255)]
    public string $internalName = '';

    #[NotNull]
    public null|WeeklyMenuMealType $mealType = null;

    #[NotNull]
    public null|UuidInterface $dishTypeId = null;

    public null|UuidInterface $dietId = null;

    #[PositiveOrZero]
    public null|string $energyValue = null;

    #[PositiveOrZero]
    public null|string $fats = null;

    #[PositiveOrZero]
    public null|string $carbohydrates = null;

    #[PositiveOrZero]
    public null|string $proteins = null;

    /**
     * @var Collection<int, MealVariantFormData>
     */
    #[Valid]
    #[Count(max: 4, maxMessage: 'Maximálně 4 varianty')]
    public Collection $variants;

    public function __construct()
    {
        $this->variants = new ArrayCollection();
    }
}
