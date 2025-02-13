<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use WBoost\Web\FormData\ManualColorFormData;
use WBoost\Web\Value\ManualColorType;

/**
 * @extends AbstractType<ManualColorFormData>
 */
final class ManualColorFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $colorInputType = ($options['color_hidden'] ?? false) === true ? HiddenType::class : TextType::class;

        $builder->add('order', HiddenType::class);

        $builder->add('color', $colorInputType, [
            'label' => 'HEX',
            'required' => true,
            'attr' => [
                'placeholder' => 'HEX',
            ],
        ]);

        $builder->add('c', TextType::class, [
            'label' => 'C',
            'attr' => [
                'placeholder' => 'C',
            ],
            'required' => false,
        ]);

        $builder->add('m', TextType::class, [
            'label' => 'M',
            'attr' => [
                'placeholder' => 'M',
            ],
            'required' => false,
        ]);

        $builder->add('y', TextType::class, [
            'label' => 'Y',
            'attr' => [
                'placeholder' => 'Y',
            ],
            'required' => false,
        ]);

        $builder->add('k', TextType::class, [
            'label' => 'K',
            'attr' => [
                'placeholder' => 'K',
            ],
            'required' => false,
        ]);

        $builder->add('pantone', TextType::class, [
            'label' => 'Pantone',
            'attr' => [
                'placeholder' => 'Pantone',
            ],
            'required' => false,
        ]);

        $builder->add('type', ChoiceType::class, [
            'label' => 'Mapování',
            'required' => false,
            'attr' => [
                'placeholder' => 'Mapování',
            ],
            'choices' => [
               '-' => '',
               'Primární' => ManualColorType::Primary->value,
               'Sekundární' => ManualColorType::Secondary->value,
           ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ManualColorFormData::class,
            'color_hidden' => false,
        ]);
    }
}
