<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use WBoost\Web\FormData\TemplateVariantFormData;
use WBoost\Web\Value\DimensionPreset;
use WBoost\Web\Value\DimensionUnit;

/**
 * @extends AbstractType<TemplateVariantFormData>
 */
final class TemplateVariantFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Filled by the Instagram preset buttons, cleared by any manual edit
        // (template-dimension Stimulus controller). Invalid values silently
        // fall back to a free-form dimension.
        $builder->add('preset', HiddenType::class, [
            'required' => false,
        ]);
        $builder->get('preset')->addModelTransformer(new CallbackTransformer(
            static fn (null|DimensionPreset $preset): string => $preset->value ?? '',
            static fn (null|string $value): null|DimensionPreset => $value === null || $value === ''
                ? null
                : DimensionPreset::tryFrom($value),
        ));

        $builder->add('unit', EnumType::class, [
            'label' => 'Jednotka',
            'class' => DimensionUnit::class,
            'choice_label' => static fn (DimensionUnit $unit): string => $unit->label(),
        ]);

        $builder->add('width', NumberType::class, [
            'label' => 'Šířka',
            'html5' => true,
            'scale' => 2,
            'attr' => ['min' => 0, 'step' => 'any'],
        ]);

        $builder->add('height', NumberType::class, [
            'label' => 'Výška',
            'html5' => true,
            'scale' => 2,
            'attr' => ['min' => 0, 'step' => 'any'],
        ]);

        $builder->add('bleed', NumberType::class, [
            'label' => 'Spadávka (mm)',
            'required' => false,
            'html5' => true,
            'scale' => 1,
            'empty_data' => '0',
            'attr' => ['min' => 0, 'max' => TemplateVariantFormData::MAX_BLEED_MM, 'step' => 'any'],
        ]);

        $builder->add('cropMarks', CheckboxType::class, [
            'label' => 'Ořezové značky',
            'required' => false,
        ]);

        // Filled by the background gallery picker widget
        // (_background_gallery_picker.html.twig) with the picked gallery
        // file's id; uploads happen through the gallery itself. A document's
        // page size has no background (`with_background: false`).
        if ($options['with_background'] === true) {
            $builder->add('backgroundImageId', HiddenType::class, [
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TemplateVariantFormData::class,
            'with_background' => true,
        ]);
        $resolver->setAllowedTypes('with_background', 'bool');
    }
}
