<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use WBoost\Web\FormData\SocialNetworkTemplateVariantEditorFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<SocialNetworkTemplateVariantEditorFormData>
 */
final class SocialNetworkTemplateVariantEditorFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('canvas', HiddenType::class);
        $builder->add('textInputs', HiddenType::class);
        $builder->add('event', HiddenType::class);
        $builder->add('imagePreview', HiddenType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SocialNetworkTemplateVariantEditorFormData::class,
        ]);
    }
}
