<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use WBoost\Web\FormData\FontFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<FontFormData>
 */
final class FontFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('font', FileType::class, [
            'label' => 'Font',
            'required' => true,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => FontFormData::class,
        ]);
    }
}
