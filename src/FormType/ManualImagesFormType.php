<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use WBoost\Web\FormData\ManualImagesFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Constraints\Range;

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
                    maxSize: '10m',
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

        $builder->add('logoHorizontalDisplayWidth', IntegerType::class, $this->displayWidthOptions());

        $builder->add('logoHorizontalOwnPage', CheckboxType::class, $this->ownPageOptions());

        $builder->add('logoVertical', FileType::class, [
            'label' => 'Logo vertikální',
            'required' => false,
            'constraints' => [
                new Image(
                    maxSize: '10m',
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

        $builder->add('logoVerticalDisplayWidth', IntegerType::class, $this->displayWidthOptions());

        $builder->add('logoVerticalOwnPage', CheckboxType::class, $this->ownPageOptions());

        $builder->add('logoHorizontalWithClaim', FileType::class, [
            'label' => 'Logo horizontální se sloganem',
            'required' => false,
            'constraints' => [
                new Image(
                    maxSize: '10m',
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

        $builder->add('logoHorizontalWithClaimDisplayWidth', IntegerType::class, $this->displayWidthOptions());

        $builder->add('logoHorizontalWithClaimOwnPage', CheckboxType::class, $this->ownPageOptions());

        $builder->add('logoVerticalWithClaim', FileType::class, [
            'label' => 'Logo vertikální se sloganem',
            'required' => false,
            'constraints' => [
                new Image(
                    maxSize: '10m',
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

        $builder->add('logoVerticalWithClaimDisplayWidth', IntegerType::class, $this->displayWidthOptions());

        $builder->add('logoVerticalWithClaimOwnPage', CheckboxType::class, $this->ownPageOptions());

        $builder->add('logoSymbol', FileType::class, [
            'label' => 'Symbol',
            'required' => false,
            'constraints' => [
                new Image(
                    maxSize: '10m',
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

        $builder->add('logoSymbolDisplayWidth', IntegerType::class, $this->displayWidthOptions());

        $builder->add('logoSymbolOwnPage', CheckboxType::class, $this->ownPageOptions());
    }

    /**
     * Shared options for the "logo width in the manual (%)" fields.
     *
     * @return array<string, mixed>
     */
    private function displayWidthOptions(): array
    {
        return [
            'label' => 'Šířka loga v manuálu (%)',
            'required' => false,
            'help' => '0 nebo prázdné = výchozí velikost. 1–100 = šířka v procentech rámečku (výška zůstává stejná).',
            'constraints' => [
                new Range(
                    min: 0,
                    max: 100,
                    notInRangeMessage: 'Zadejte hodnotu mezi {{ min }} a {{ max }}.',
                ),
            ],
            'attr' => [
                'min' => 0,
                'max' => 100,
                'step' => 1,
                'placeholder' => 'výchozí',
            ],
        ];
    }

    /**
     * Shared options for the "show this variant on a page of its own" boxes.
     *
     * @return array<string, mixed>
     */
    private function ownPageOptions(): array
    {
        return [
            'label' => 'Zobrazovat variantu na samostatné stránce',
            'required' => false,
            'help' => 'Varianta dostane v manuálu vlastní stránku se všemi svými barevnými variantami a zmizí ze společných stránek.',
        ];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ManualImagesFormData::class,
        ]);
    }
}
