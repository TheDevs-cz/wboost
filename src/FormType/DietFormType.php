<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use WBoost\Web\FormData\DietFormData;
use WBoost\Web\Value\DietCode;

/**
 * @extends AbstractType<DietFormData>
 */
final class DietFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Název',
            'required' => true,
            'empty_data' => '',
        ]);

        $builder->add('codes', ChoiceType::class, [
            'label' => 'Kódy diet',
            'required' => false,
            'multiple' => true,
            'expanded' => true,
            'choices' => array_combine(
                array_map(fn(DietCode $code) => $code->label(), DietCode::cases()),
                array_map(fn(DietCode $code) => $code->value, DietCode::cases()),
            ),
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DietFormData::class,
        ]);
    }
}
