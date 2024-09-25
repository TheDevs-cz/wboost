<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use WBoost\Web\Entity\Font;
use WBoost\Web\FormData\ManualFontsFormData;
use WBoost\Web\Value\ManualFontType;

/**
 * @extends AbstractType<ManualFontsFormData>
 */
final class ManualFontsFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<Font> $fonts */
        $fonts = $options['project_fonts'];
        $choices = [];

        foreach ($fonts as $font) {
            $choices[$font->name] = $font->id->toString();
        }

        $builder->add('font', ChoiceType::class, [
            'label' => 'Font',
            'required' => true,
            'placeholder' => '- Vybrat -',
            'choices' => $choices,
        ]);

        $builder->add('type', ChoiceType::class, [
            'label' => 'Typ',
            'required' => true,
            'placeholder' => '- Vybrat -',
            'choices' => [
                'Primární' => ManualFontType::Primary->value,
                'Sekundární' => ManualFontType::Secondary->value,
            ],
        ]);

        $builder->add('color', TextType::class, [
            'label' => 'Barva',
            'required' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ManualFontsFormData::class,
            'project_fonts' => [],
        ]);
    }
}
