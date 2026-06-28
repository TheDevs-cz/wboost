<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use WBoost\Web\FormData\SetPasswordFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<SetPasswordFormData>
 */
final class SetPasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'Hesla se musí shodovat.',
                'first_options' => ['label' => 'Heslo'],
                'second_options' => ['label' => 'Heslo znovu'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SetPasswordFormData::class,
        ]);
    }
}
