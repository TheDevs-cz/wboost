<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use WBoost\Web\FormData\EditUserFormData;
use WBoost\Web\Value\UserRoleChoice;

/**
 * @extends AbstractType<EditUserFormData>
 */
final class EditUserFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Jméno',
                'required' => false,
            ])
            ->add('role', ChoiceType::class, [
                'label' => 'Role',
                'choices' => UserRoleChoice::CHOICES,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EditUserFormData::class,
        ]);
    }
}
