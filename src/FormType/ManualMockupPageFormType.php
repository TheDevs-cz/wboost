<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use WBoost\Web\FormData\ManualMockupPageFormData;
use WBoost\Web\Value\MockupPageLayout;

/**
 * @extends AbstractType<ManualMockupPageFormData>
 */
final class ManualMockupPageFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Název',
            'required' => true,
            'constraints' => [
                new NotBlank(message: 'Zadejte název stránky.'),
            ],
        ]);

        if ($options['allow_layout_choice'] === true) {
            $builder->add('layout', EnumType::class, [
                'class' => MockupPageLayout::class,
                'expanded' => true,
                'label' => false,
                'constraints' => [
                    new NotNull(message: 'Vyberte rozložení stránky.'),
                ],
            ]);
        }

        $builder->add('removeImages', CollectionType::class, [
            'entry_type' => HiddenType::class,
            'entry_options' => [
                'label' => false,
            ],
            'allow_add' => false,
            'allow_delete' => false,
        ]);

        $builder->add('images', CollectionType::class, [
            'entry_type' => FileType::class,
            'entry_options' => [
                'label' => false,
                'required' => false,
                'constraints' => [
                    new Image(
                        maxSize: '2m',
                    ),
                ],
            ],
            'allow_add' => false,
            'allow_delete' => false,
            'by_reference' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ManualMockupPageFormData::class,
            'allow_layout_choice' => false,
        ]);

        $resolver->setAllowedTypes('allow_layout_choice', 'bool');
    }
}
