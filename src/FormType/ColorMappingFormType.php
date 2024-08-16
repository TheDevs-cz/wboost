<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use WBoost\Web\FormData\ColorMappingFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ColorMappingFormData>
 */
final class ColorMappingFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('c1', TextType::class, [
            'label' => 'C1',
            'required' => false,
        ]);

        $builder->add('c2', TextType::class, [
            'label' => 'C2',
            'required' => false,
        ]);

        $builder->add('c3', TextType::class, [
            'label' => 'C3',
            'required' => false,
        ]);

        $builder->add('c4', TextType::class, [
            'label' => 'C4',
            'required' => false,
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
            'data_class' => ColorMappingFormData::class,
            'allow_extra_fields' => true,
        ]);
    }
}
