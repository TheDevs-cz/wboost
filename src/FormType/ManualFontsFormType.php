<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use WBoost\Web\Entity\Font;
use WBoost\Web\FormData\ManualFontsFormData;
use WBoost\Web\Query\GetFonts;

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

        $builder->add('primaryFont', ChoiceType::class, [
            'label' => 'Primární font',
            'required' => false,
            'placeholder' => '- Vybrat -',
            'choices' => $choices,
        ]);

        $builder->add('secondaryFont', ChoiceType::class, [
            'label' => 'Sekundární font',
            'required' => false,
            'placeholder' => '- Vybrat -',
            'choices' => $choices,
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
