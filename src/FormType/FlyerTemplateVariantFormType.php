<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;
use WBoost\Web\FormData\FlyerTemplateVariantFormData;
use WBoost\Web\Value\DimensionUnit;

/**
 * @extends AbstractType<FlyerTemplateVariantFormData>
 */
final class FlyerTemplateVariantFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
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

        $builder->add('backgroundImage', FileType::class, [
            'label' => 'Obrázek pozadí',
            'required' => false,
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
            'data_class' => FlyerTemplateVariantFormData::class,
        ]);
    }
}
