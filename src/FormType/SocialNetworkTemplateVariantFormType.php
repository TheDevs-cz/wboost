<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;
use WBoost\Web\FormData\SocialNetworkTemplateVariantFormData;

/**
 * @extends AbstractType<SocialNetworkTemplateVariantFormData>
 */
final class SocialNetworkTemplateVariantFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
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
            'data_class' => SocialNetworkTemplateVariantFormData::class,
        ]);
    }
}
