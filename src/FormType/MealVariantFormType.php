<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use WBoost\Web\Entity\Diet;
use WBoost\Web\Entity\Meal;
use WBoost\Web\FormData\MealVariantFormData;

/**
 * @extends AbstractType<MealVariantFormData>
 */
final class MealVariantFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('id', HiddenType::class, [
            'getter' => fn(MealVariantFormData $data) => $data->id?->toString(),
            'setter' => fn(MealVariantFormData $data, ?string $value) => $data->id = $value !== null && $value !== '' ? Uuid::fromString($value) : null,
        ]);

        // Use PRE_SET_DATA to dynamically add the mode field with correct data
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $form = $event->getForm();
            $data = $event->getData();

            // Get the mode value from data, default to 'reference' for new entries
            $modeValue = MealVariantFormData::MODE_REFERENCE;
            if ($data instanceof MealVariantFormData) {
                $modeValue = $data->mode;
            }

            $form->add('mode', ChoiceType::class, [
                'label' => 'Typ varianty',
                'required' => true,
                'expanded' => true,
                'choices' => [
                    'Odkaz na jídlo' => MealVariantFormData::MODE_REFERENCE,
                    'Ruční zadání' => MealVariantFormData::MODE_MANUAL,
                ],
                'data' => $modeValue,
                'attr' => [
                    'class' => 'variant-mode-selector',
                ],
            ]);
        });

        $builder->add('name', TextType::class, [
            'label' => 'Název varianty',
            'required' => false,
            'empty_data' => '',
            'attr' => [
                'placeholder' => 'např. Bezlepková verze',
            ],
        ]);

        /** @var array<Meal> $meals */
        $meals = $options['meals'];
        $mealChoices = [];
        foreach ($meals as $meal) {
            $mealChoices[$meal->internalName . ' - ' . $meal->name] = $meal->id->toString();
        }

        $builder->add('referenceMealId', ChoiceType::class, [
            'label' => 'Vybrat jídlo',
            'required' => false,
            'placeholder' => '-- Vyberte jídlo --',
            'choices' => $mealChoices,
            'choice_value' => fn(?string $value) => $value,
            'getter' => fn(MealVariantFormData $data) => $data->referenceMealId?->toString(),
            'setter' => fn(MealVariantFormData $data, ?string $value) => $data->referenceMealId = $value !== null && $value !== '' ? Uuid::fromString($value) : null,
        ]);

        /** @var array<Diet> $diets */
        $diets = $options['diets'];
        $dietChoices = [];
        foreach ($diets as $diet) {
            $dietChoices[$diet->name . ' (' . $diet->codesLabel() . ')'] = $diet->id->toString();
        }

        $builder->add('dietId', ChoiceType::class, [
            'label' => 'Dieta',
            'required' => false,
            'placeholder' => '-- Vyberte dietu --',
            'choices' => $dietChoices,
            'choice_value' => fn(?string $value) => $value,
            'getter' => fn(MealVariantFormData $data) => $data->dietId?->toString(),
            'setter' => fn(MealVariantFormData $data, ?string $value) => $data->dietId = $value !== null && $value !== '' ? Uuid::fromString($value) : null,
        ]);

        $builder->add('energyValue', NumberType::class, [
            'label' => 'Energetická hodnota (kJ)',
            'required' => false,
            'scale' => 2,
            'html5' => true,
            'attr' => [
                'step' => '0.01',
                'min' => '0',
            ],
        ]);

        $builder->add('fats', NumberType::class, [
            'label' => 'Tuky (g)',
            'required' => false,
            'scale' => 2,
            'html5' => true,
            'attr' => [
                'step' => '0.01',
                'min' => '0',
            ],
        ]);

        $builder->add('carbohydrates', NumberType::class, [
            'label' => 'Sacharidy (g)',
            'required' => false,
            'scale' => 2,
            'html5' => true,
            'attr' => [
                'step' => '0.01',
                'min' => '0',
            ],
        ]);

        $builder->add('proteins', NumberType::class, [
            'label' => 'Bílkoviny (g)',
            'required' => false,
            'scale' => 2,
            'html5' => true,
            'attr' => [
                'step' => '0.01',
                'min' => '0',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MealVariantFormData::class,
            'empty_data' => fn() => new MealVariantFormData(),
            'diets' => [],
            'meals' => [],
        ]);
    }
}
