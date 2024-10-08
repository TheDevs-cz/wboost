<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use WBoost\Web\FormData\RequestPasswordResetFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<RequestPasswordResetFormData>
 */
final class RequestPasswordResetFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
                'required' => true,
                'label' => 'E-mail',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RequestPasswordResetFormData::class,
        ]);
    }
}
