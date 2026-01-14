<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use WBoost\Web\Entity\Diet;
use WBoost\Web\Entity\DishType;
use WBoost\Web\FormData\MealFormData;
use WBoost\Web\Value\WeeklyMenuMealType;

/**
 * @extends AbstractType<MealFormData>
 */
final class MealFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('internalName', TextType::class, [
            'label' => 'Interní název jídla',
            'help' => 'Název pro interní použití, zobrazuje se při výběru jídla',
            'required' => true,
            'empty_data' => '',
        ]);

        $builder->add('name', TextType::class, [
            'label' => 'Název varianty',
            'help' => 'Zkrácený název zobrazovaný ve výstupech (jídelníček, PDF)',
            'required' => true,
            'empty_data' => '',
        ]);

        $builder->add('mealType', EnumType::class, [
            'label' => 'Typ jídla',
            'required' => true,
            'class' => WeeklyMenuMealType::class,
            'choice_label' => fn(WeeklyMenuMealType $type) => $type->label(),
            'placeholder' => '-- Vyberte typ jídla --',
        ]);

        /** @var array<DishType> $dishTypes */
        $dishTypes = $options['dish_types'];
        $builder->add('dishTypeId', ChoiceType::class, [
            'label' => 'Druh jídla',
            'required' => true,
            'placeholder' => '-- Vyberte druh jídla --',
            'choices' => array_combine(
                array_map(fn(DishType $dt) => $dt->name, $dishTypes),
                array_map(fn(DishType $dt) => $dt->id->toString(), $dishTypes),
            ),
            'choice_value' => fn(?string $value) => $value,
            'getter' => fn(MealFormData $data) => $data->dishTypeId?->toString(),
            'setter' => fn(MealFormData $data, ?string $value) => $data->dishTypeId = $value !== null && $value !== '' ? Uuid::fromString($value) : null,
        ]);

        /** @var array<Diet> $diets */
        $diets = $options['diets'];
        $dietChoices = ['' => null];
        foreach ($diets as $diet) {
            $dietChoices[$diet->name . ' (' . $diet->codesLabel() . ')'] = $diet->id->toString();
        }

        $builder->add('dietId', ChoiceType::class, [
            'label' => 'Dieta',
            'required' => false,
            'choices' => $dietChoices,
            'choice_value' => fn(?string $value) => $value,
            'getter' => fn(MealFormData $data) => $data->dietId?->toString(),
            'setter' => fn(MealFormData $data, ?string $value) => $data->dietId = $value !== null && $value !== '' ? Uuid::fromString($value) : null,
        ]);

        $builder->add('variants', CollectionType::class, [
            'entry_type' => MealVariantFormType::class,
            'entry_options' => [
                'label' => false,
                'diets' => $diets,
            ],
            'allow_add' => true,
            'allow_delete' => true,
            'by_reference' => false,
            'label' => 'Dietní varianty (max. 4)',
            'required' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MealFormData::class,
            'dish_types' => [],
            'diets' => [],
        ]);
    }
}
