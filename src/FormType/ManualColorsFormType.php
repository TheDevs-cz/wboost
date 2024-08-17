<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use WBoost\Web\FormData\ManualColorsFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ManualColorsFormData>
 */
final class ManualColorsFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('primaryColors', CollectionType::class, [
            'label' => false,
            'entry_type' => TextType::class,
            'entry_options' => [
                'required' => false,
                'label' => false,
            ],
            'allow_add' => false,
            'allow_delete' => false,
            'prototype' => false,
        ]);

        $builder->add('secondaryColors', CollectionType::class, [
            'label' => false,
            'entry_type' => TextType::class,
            'entry_options' => [
                'required' => false,
                'label' => false,
            ],
            'allow_add' => true,
            'allow_delete' => true,
            'attr' => ['data-controller' => 'form-collection'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ManualColorsFormData::class,
            'allow_extra_fields' => true,
        ]);
    }
}