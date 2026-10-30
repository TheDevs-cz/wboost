<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use WBoost\Web\FormData\TemplateVariantFormData;
use WBoost\Web\FormData\TemplateVariantPrintSettingsFormData;

/**
 * @extends AbstractType<TemplateVariantPrintSettingsFormData>
 */
final class TemplateVariantPrintSettingsFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('bleed', NumberType::class, [
            'label' => 'Spadávka (mm)',
            'required' => false,
            'html5' => true,
            'scale' => 1,
            'empty_data' => '0',
            'attr' => ['min' => 0, 'max' => TemplateVariantFormData::MAX_BLEED_MM, 'step' => 'any'],
        ]);

        $builder->add('cropMarks', CheckboxType::class, [
            'label' => 'Ořezové značky',
            'required' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TemplateVariantPrintSettingsFormData::class,
        ]);
    }
}
