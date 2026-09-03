<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use WBoost\Web\FormData\ManualPageTextFormData;

/**
 * @extends AbstractType<ManualPageTextFormData>
 */
final class ManualPageTextFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('title', TextType::class, [
            'label' => 'Nadpis stránky',
            'required' => false,
            'constraints' => [
                new Length(max: 255),
            ],
            'attr' => [
                'placeholder' => $options['default_title'],
            ],
            'help' => 'Nechte prázdné pro výchozí nadpis.',
        ]);

        $builder->add('description', TextareaType::class, [
            'label' => 'Text stránky',
            'required' => false,
            'attr' => [
                'rows' => 8,
                'placeholder' => $options['default_description'],
            ],
            'help' => 'Nechte prázdné pro výchozí text. Odstavce oddělte prázdným řádkem.',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ManualPageTextFormData::class,
            'default_title' => '',
            'default_description' => '',
        ]);

        $resolver->setAllowedTypes('default_title', 'string');
        $resolver->setAllowedTypes('default_description', 'string');
    }
}
