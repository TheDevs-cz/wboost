<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;
use WBoost\Web\FormData\ManualMockupPageFormData;

/**
 * @extends AbstractType<ManualMockupPageFormData>
 */
final class ManualMockupPageFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Název',
            'required' => true,
        ]);

        $builder->add('images', CollectionType::class, [
            'entry_type' => FileType::class,
            'entry_options' => [
                'label' => false,
                'required' => false,
                'constraints' => [
                    new Image(
                        maxSize: '2m',
                    ),
                ],
            ],
            'allow_add' => false,
            'allow_delete' => false,
            'by_reference' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ManualMockupPageFormData::class,
        ]);
    }
}
