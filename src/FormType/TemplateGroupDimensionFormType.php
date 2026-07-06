<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;
use WBoost\Web\FormData\TemplateGroupDimensionFormData;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\TemplateDimension;

/**
 * @extends AbstractType<TemplateGroupDimensionFormData>
 */
final class TemplateGroupDimensionFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('module', ChoiceType::class, [
            'label' => 'Modul',
            'expanded' => true,
            'choices' => [
                'Sociální sítě' => TemplateGroupDimensionFormData::MODULE_SOCIAL,
                'Vlastní rozměr (Šablony)' => TemplateGroupDimensionFormData::MODULE_CUSTOM,
            ],
        ]);

        $builder->add('socialDimension', EnumType::class, [
            'label' => 'Rozměr',
            'class' => TemplateDimension::class,
            'required' => false,
            'choice_label' => static fn (TemplateDimension $dimension): string => sprintf(
                '%s (%dx%d px)',
                $dimension->value,
                $dimension->width(),
                $dimension->height(),
            ),
        ]);

        $builder->add('unit', EnumType::class, [
            'label' => 'Jednotka',
            'class' => DimensionUnit::class,
            'required' => false,
            'choice_label' => static fn (DimensionUnit $unit): string => $unit->label(),
        ]);

        $builder->add('width', NumberType::class, [
            'label' => 'Šířka',
            'required' => false,
            'html5' => true,
            'scale' => 2,
            'attr' => ['min' => 0, 'step' => 'any'],
        ]);

        $builder->add('height', NumberType::class, [
            'label' => 'Výška',
            'required' => false,
            'html5' => true,
            'scale' => 2,
            'attr' => ['min' => 0, 'step' => 'any'],
        ]);

        $builder->add('backgroundImage', FileType::class, [
            'label' => 'Obrázek pozadí',
            'required' => true,
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
            'data_class' => TemplateGroupDimensionFormData::class,
        ]);
    }
}
