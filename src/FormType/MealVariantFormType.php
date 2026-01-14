<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use WBoost\Web\Entity\Diet;
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

        $builder->add('name', TextType::class, [
            'label' => 'Název varianty',
            'required' => true,
            'empty_data' => '',
            'attr' => ['placeholder' => 'např. Bezlepková verze'],
        ]);

        /** @var array<Diet> $diets */
        $diets = $options['diets'];
        $dietChoices = [];
        foreach ($diets as $diet) {
            $dietChoices[$diet->name . ' (' . $diet->codesLabel() . ')'] = $diet->id->toString();
        }

        $builder->add('dietId', ChoiceType::class, [
            'label' => 'Dieta',
            'required' => true,
            'placeholder' => '-- Vyberte dietu --',
            'choices' => $dietChoices,
            'choice_value' => fn(?string $value) => $value,
            'getter' => fn(MealVariantFormData $data) => $data->dietId?->toString(),
            'setter' => fn(MealVariantFormData $data, ?string $value) => $data->dietId = $value !== null && $value !== '' ? Uuid::fromString($value) : null,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MealVariantFormData::class,
            'diets' => [],
        ]);
    }
}
