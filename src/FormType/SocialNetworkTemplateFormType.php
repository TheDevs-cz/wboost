<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use WBoost\Web\Entity\SocialNetworkCategory;
use WBoost\Web\FormData\SocialNetworkTemplateFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;

/**
 * @extends AbstractType<SocialNetworkTemplateFormData>
 */
final class SocialNetworkTemplateFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<SocialNetworkCategory> $categories */
        $categories = $options['categories'];
        $choices = [];

        foreach ($categories as $category) {
            $choices[$category->name] = $category->id->toString();
        }

        $builder->add('category', ChoiceType::class, [
            'label' => 'Kategorie',
            'required' => false,
            'placeholder' => '- Bez kategorie -',
            'choices' => $choices,
        ]);

        $builder->add('name', TextType::class, [
            'label' => 'Název šablony',
            'required' => true,
            'empty_data' => ''
        ]);

        $builder->add('image', FileType::class, [
            'label' => 'Úvodní obrázek',
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
            'data_class' => SocialNetworkTemplateFormData::class,
            'categories' => [],
        ]);
    }
}
