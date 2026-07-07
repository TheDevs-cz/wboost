<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;
use WBoost\Web\Entity\CustomTemplateCategory;
use WBoost\Web\Entity\SocialNetworkCategory;
use WBoost\Web\FormData\TemplateGroupFormData;
use WBoost\Web\Value\TemplateDimension;

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

        /** @var array<SocialNetworkCategory> $socialCategories */
        $socialCategories = $options['social_categories'];
        $socialChoices = [];

        foreach ($socialCategories as $category) {
            $socialChoices[$category->name] = $category->id->toString();
        }

        $builder->add('socialCategory', ChoiceType::class, [
            'label' => 'Kategorie (sociální sítě)',
            'required' => false,
            'placeholder' => '- Bez kategorie -',
            'choices' => $socialChoices,
        ]);

        /** @var array<CustomTemplateCategory> $customCategories */
        $customCategories = $options['custom_categories'];
        $customChoices = [];

        foreach ($customCategories as $category) {
            $customChoices[$category->name] = $category->id->toString();
        }

        $builder->add('customCategory', ChoiceType::class, [
            'label' => 'Kategorie (šablony)',
            'required' => false,
            'placeholder' => '- Bez kategorie -',
            'choices' => $customChoices,
        ]);

        $builder->add('socialDimensions', EnumType::class, [
            'label' => 'Rozměry pro sociální sítě',
            'class' => TemplateDimension::class,
            'multiple' => true,
            'expanded' => true,
            'required' => false,
            'choice_label' => static fn (TemplateDimension $dimension): string => sprintf(
                '%s (%dx%d px)',
                $dimension->value,
                $dimension->width(),
                $dimension->height(),
            ),
        ]);

        foreach (TemplateDimension::cases() as $dimension) {
            $builder->add('background' . $dimension->name, FileType::class, [
                'label' => sprintf('Pozadí %s', $dimension->value),
                'required' => false,
                'constraints' => [
                    new Image(
                        maxSize: '2m',
                    ),
                ],
            ]);
        }

        $builder->add('commonBackground', FileType::class, [
            'label' => 'Společné pozadí (použít pro všechny vybrané rozměry)',
            'required' => false,
            'constraints' => [
                new Image(
                    maxSize: '2m',
                ),
            ],
        ]);

        // "Create from existing template" source, carried through submits as
        // hidden fields (the picker page fills them via query parameters).
        $builder->add('sourceModule', HiddenType::class, [
            'required' => false,
        ]);

        $builder->add('sourceVariantId', HiddenType::class, [
            'required' => false,
        ]);

        $builder->add('customDimensions', CollectionType::class, [
            'label' => 'Vlastní rozměry',
            'entry_type' => CustomTemplateVariantFormType::class,
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
            'social_categories' => [],
            'custom_categories' => [],
        ]);
    }
}
