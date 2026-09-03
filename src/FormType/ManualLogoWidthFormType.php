<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Range;
use WBoost\Web\FormData\ManualLogoWidthFormData;

/**
 * @extends AbstractType<ManualLogoWidthFormData>
 */
final class ManualLogoWidthFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('displayWidth', IntegerType::class, [
            'label' => 'Šířka loga v tomto rámečku (%)',
            'required' => false,
            'help' => $options['fallback_help'],
            'constraints' => [
                new Range(
                    min: 0,
                    max: 100,
                    notInRangeMessage: 'Zadejte hodnotu mezi {{ min }} a {{ max }}.',
                ),
            ],
            'attr' => [
                'min' => 0,
                'max' => 100,
                'step' => 1,
                'placeholder' => $options['fallback_placeholder'],
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ManualLogoWidthFormData::class,
            'fallback_help' => '',
            'fallback_placeholder' => '',
        ]);

        $resolver->setAllowedTypes('fallback_help', 'string');
        $resolver->setAllowedTypes('fallback_placeholder', 'string');
    }
}
