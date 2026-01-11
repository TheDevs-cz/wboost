<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use WBoost\Web\FormData\DuplicateWeeklyMenuFormData;

/**
 * @extends AbstractType<DuplicateWeeklyMenuFormData>
 */
final class DuplicateWeeklyMenuFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('validFrom', DateType::class, [
            'label' => 'Platnost od',
            'required' => true,
            'widget' => 'single_text',
            'input' => 'datetime_immutable',
            'html5' => false,
            'format' => 'yyyy-MM-dd',
        ]);

        $builder->add('validTo', DateType::class, [
            'label' => 'Platnost do',
            'required' => true,
            'widget' => 'single_text',
            'input' => 'datetime_immutable',
            'html5' => false,
            'format' => 'yyyy-MM-dd',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DuplicateWeeklyMenuFormData::class,
        ]);
    }
}
