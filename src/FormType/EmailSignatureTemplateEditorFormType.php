<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use WBoost\Web\FormData\EmailSignatureTemplateEditorFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<EmailSignatureTemplateEditorFormData>
 */
final class EmailSignatureTemplateEditorFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('code', HiddenType::class, [
            'empty_data' => '',
        ]);

        $builder->add('textPlaceholders', HiddenType::class, [
            'empty_data' => '',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EmailSignatureTemplateEditorFormData::class,
        ]);
    }
}
