<?php

declare(strict_types=1);

namespace BrandManuals\Web\FormType;

use BrandManuals\Web\FormData\ProjectImagesFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;

/**
 * @extends AbstractType<ProjectImagesFormData>
 */
final class ProjectImagesFormType extends AbstractType
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
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProjectImagesFormData::class,
        ]);
    }
}
