<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\Extension\Core\Type\EnumType;
use WBoost\Web\FormData\ManualFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use WBoost\Web\Value\ManualType;

/**
 * @extends AbstractType<ManualFormData>
 */
final class ManualFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Název manuálu',
            'required' => true,
        ]);

        $builder->add('type', EnumType::class, [
            'label' => 'Typ manuálu',
            'class' => ManualType::class,
            'required' => true,
            'placeholder' => '- vyberte -',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ManualFormData::class,
        ]);
    }
}
