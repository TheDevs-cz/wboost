<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use WBoost\Web\Entity\TemplateCategory;
use WBoost\Web\FormData\TemplateDocumentFormData;

/**
 * Creating a document (with its page size) or — `with_dimension: false` —
 * renaming / recategorising an existing one (the size never changes: every
 * page is laid out for it).
 *
 * @extends AbstractType<TemplateDocumentFormData>
 */
final class TemplateDocumentFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<TemplateCategory> $categories */
        $categories = $options['categories'];
        $choices = [];

        foreach ($categories as $category) {
            $choices[$category->name] = $category->id->toString();
        }

        $builder->add('name', TextType::class, [
            'label' => 'Název dokumentu',
            'empty_data' => '',
        ]);

        $builder->add('category', ChoiceType::class, [
            'label' => 'Kategorie',
            'required' => false,
            'placeholder' => '- Bez kategorie -',
            'choices' => $choices,
        ]);

        if ($options['with_dimension'] === true) {
            $builder->add('dimension', TemplateVariantFormType::class, [
                'label' => false,
                'with_background' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TemplateDocumentFormData::class,
            'categories' => [],
            'with_dimension' => true,
        ]);
        $resolver->setAllowedTypes('with_dimension', 'bool');
    }
}
