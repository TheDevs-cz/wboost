<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\Extension\Core\Type\TextType;
use WBoost\Web\FormData\ManualImagesFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;

/**
 * @extends AbstractType<ManualImagesFormData>
 */
final class ManualImagesFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('logoHorizontal', FileType::class, [
            'label' => 'Logo horizontální',
            'required' => false,
            'constraints' => [
                new Image(
                    maxSize: '2m',
                    mimeTypes: ['image/svg+xml'],
                    mimeTypesMessage: 'Soubor není validní SVG obrázek.',
                ),
            ],
        ]);

        $builder->add('logoHorizontalWidthInfo', TextType::class, [
            'label' => 'Šířka',
            'required' => false,
        ]);

        $builder->add('logoHorizontalHeightInfo', TextType::class, [
            'label' => 'Výška',
            'required' => false,
        ]);

        $builder->add('logoVertical', FileType::class, [
            'label' => 'Logo vertikální',
            'required' => false,
            'constraints' => [
                new Image(
                    maxSize: '2m',
                    mimeTypes: ['image/svg+xml'],
                    mimeTypesMessage: 'Soubor není validní SVG obrázek.',
                ),
            ],
        ]);

        $builder->add('logoVerticalWidthInfo', TextType::class, [
            'label' => 'Šířka',
            'required' => false,
        ]);

        $builder->add('logoVerticalHeightInfo', TextType::class, [
            'label' => 'Výška',
            'required' => false,
        ]);

        $builder->add('logoHorizontalWithClaim', FileType::class, [
            'label' => 'Logo horizontální se sloganem',
            'required' => false,
            'constraints' => [
                new Image(
                    maxSize: '2m',
                    mimeTypes: ['image/svg+xml'],
                    mimeTypesMessage: 'Soubor není validní SVG obrázek.',
                ),
            ],
        ]);

        $builder->add('logoHorizontalWithClaimWidthInfo', TextType::class, [
            'label' => 'Šířka',
            'required' => false,
        ]);

        $builder->add('logoHorizontalWithClaimHeightInfo', TextType::class, [
            'label' => 'Výška',
            'required' => false,
        ]);

        $builder->add('logoVerticalWithClaim', FileType::class, [
            'label' => 'Logo vertikální se sloganem',
            'required' => false,
            'constraints' => [
                new Image(
                    maxSize: '2m',
                    mimeTypes: ['image/svg+xml'],
                    mimeTypesMessage: 'Soubor není validní SVG obrázek.',
                ),
            ],
        ]);

        $builder->add('logoVerticalWithClaimWidthInfo', TextType::class, [
            'label' => 'Šířka',
            'required' => false,
        ]);

        $builder->add('logoVerticalWithClaimHeightInfo', TextType::class, [
            'label' => 'Výška',
            'required' => false,
        ]);

        $builder->add('logoSymbol', FileType::class, [
            'label' => 'Symbol',
            'required' => false,
            'constraints' => [
                new Image(
                    maxSize: '2m',
                    mimeTypes: ['image/svg+xml'],
                    mimeTypesMessage: 'Soubor není validní SVG obrázek.',
                ),
            ],
        ]);

        $builder->add('logoSymbolWidthInfo', TextType::class, [
            'label' => 'Šířka',
            'required' => false,
        ]);

        $builder->add('logoSymbolHeightInfo', TextType::class, [
            'label' => 'Výška',
            'required' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ManualImagesFormData::class,
        ]);
    }
}
