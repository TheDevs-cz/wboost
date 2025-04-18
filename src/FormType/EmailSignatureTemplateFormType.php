<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Validator\Constraints\Image;
use WBoost\Web\FormData\EmailSignatureTemplateFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<EmailSignatureTemplateFormData>
 */
final class EmailSignatureTemplateFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Název šablony',
            'required' => true,
            'empty_data' => ''
        ]);

        $builder->add('backgroundImage', FileType::class, [
            'label' => 'Obrázek pozadí',
            'required' => false,
            'constraints' => [
                new Image(
                    maxSize: '2m',
                ),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EmailSignatureTemplateFormData::class,
        ]);
    }
}
