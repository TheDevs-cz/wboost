<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use WBoost\Web\FormData\LogoColorsFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<LogoColorsFormData>
 */
final class LogoColorsFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('background', TextType::class, [
            'label' => 'HEX',
            'empty_data' => ''
        ]);

        $builder->add('colors', CollectionType::class, [
            'label' => false,
            'entry_type' => TextType::class,
            'entry_options' => [
                'required' => true,
                'label' => 'HEX',
            ],
            'allow_add' => false,
            'allow_delete' => false,
            'prototype' => false,
        ]);

        // Krytí per logo colour (same keys as `colors`). Background has none.
        $builder->add('opacities', CollectionType::class, [
            'label' => false,
            'entry_type' => IntegerType::class,
            'entry_options' => [
                'required' => true,
                'label' => 'Krytí',
                'attr' => ['min' => 0, 'max' => 100, 'step' => 1, 'inputmode' => 'numeric'],
            ],
            'allow_add' => false,
            'allow_delete' => false,
            'prototype' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LogoColorsFormData::class,
            'allow_extra_fields' => true,
        ]);
    }
}
