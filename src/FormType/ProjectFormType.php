<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use WBoost\Web\FormData\ProjectFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ProjectFormData>
 */
final class ProjectFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Název projektu',
            'required' => true,
            'empty_data' => ''
        ]);

        $builder->add('icon', FileType::class, [
            'label' => 'Ikona projektu',
            'help' => 'Zobrazuje se u projektu místo loga či iniciál.',
            'required' => false,
        ]);

        if ($options['allow_remove_icon'] === true) {
            $builder->add('removeIcon', CheckboxType::class, [
                'label' => 'Odstranit ikonu',
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProjectFormData::class,
            'allow_remove_icon' => false,
        ]);

        $resolver->setAllowedTypes('allow_remove_icon', 'bool');
    }
}
