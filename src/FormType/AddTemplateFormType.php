<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use WBoost\Web\FormData\AddTemplateFormData;

/**
 * Composite type behind the one-step "Nová šablona" page: the template
 * sub-form (name / category / cover image) plus the first variant's
 * dimension sub-form. Both children are the standalone types, so the field
 * definitions and constraints exist exactly once.
 *
 * @extends AbstractType<AddTemplateFormData>
 */
final class AddTemplateFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('template', TemplateFormType::class, [
            'label' => false,
            'categories' => $options['categories'],
        ]);

        $builder->add('variant', TemplateVariantFormType::class, [
            'label' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AddTemplateFormData::class,
            'categories' => [],
        ]);
    }
}
