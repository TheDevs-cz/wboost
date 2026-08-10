<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use WBoost\Web\Entity\TemplateCategory;
use WBoost\Web\FormData\TemplateGroupFormData;
use WBoost\Web\Value\DimensionPreset;

/**
 * @extends AbstractType<TemplateGroupFormData>
 */
final class TemplateGroupFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Název skupiny',
            'required' => true,
            'empty_data' => '',
        ]);

        /** @var array<TemplateCategory> $categories */
        $categories = $options['categories'];
        $categoryChoices = [];

        foreach ($categories as $category) {
            $categoryChoices[$category->name] = $category->id->toString();
        }

        $builder->add('category', ChoiceType::class, [
            'label' => 'Kategorie',
            'required' => false,
            'placeholder' => '- Bez kategorie -',
            'choices' => $categoryChoices,
        ]);

        $builder->add('presetDimensions', EnumType::class, [
            'label' => 'Rozměry pro sociální sítě',
            'class' => DimensionPreset::class,
            'multiple' => true,
            'expanded' => true,
            'required' => false,
            'choice_label' => static fn (DimensionPreset $dimension): string => sprintf(
                '%s (%dx%d px)',
                $dimension->value,
                $dimension->width(),
                $dimension->height(),
            ),
        ]);

        // Filled by the background gallery picker widgets (FileUpload ids);
        // uploads happen through the gallery itself.
        foreach (DimensionPreset::cases() as $dimension) {
            $builder->add('background' . $dimension->name, HiddenType::class, [
                'label' => sprintf('Pozadí %s', $dimension->value),
                'required' => false,
            ]);
        }

        $builder->add('commonBackground', HiddenType::class, [
            'label' => 'Společné pozadí (použít pro všechny vybrané rozměry)',
            'required' => false,
        ]);

        // "Create from existing template" source, carried through submits as
        // a hidden field (the picker page fills it via a query parameter).
        $builder->add('sourceVariantId', HiddenType::class, [
            'required' => false,
        ]);

        $builder->add('customDimensions', CollectionType::class, [
            'label' => 'Tiskové a vlastní rozměry',
            'entry_type' => TemplateVariantFormType::class,
            'entry_options' => ['label' => false],
            'allow_add' => true,
            'allow_delete' => true,
            'prototype' => true,
            'by_reference' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TemplateGroupFormData::class,
            'categories' => [],
        ]);
    }
}
