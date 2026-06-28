<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use WBoost\Web\FormData\InviteUserFormData;
use WBoost\Web\Query\GetProjects;
use WBoost\Web\Value\UserRoleChoice;

/**
 * @extends AbstractType<InviteUserFormData>
 */
final class InviteUserFormType extends AbstractType
{
    public function __construct(
        private readonly GetProjects $getProjects,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'E-mail',
                'required' => true,
            ])
            ->add('name', TextType::class, [
                'label' => 'Jméno',
                'required' => false,
            ])
            ->add('role', ChoiceType::class, [
                'label' => 'Role',
                'choices' => UserRoleChoice::CHOICES,
            ])
            ->add('projectIds', ChoiceType::class, [
                'label' => 'Předsdílet projekty',
                'help' => 'Pozvanému uživateli se tyto projekty rovnou nasdílí (pro čtení).',
                'choices' => $this->projectChoices(),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => InviteUserFormData::class,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function projectChoices(): array
    {
        $choices = [];

        foreach ($this->getProjects->all() as $project) {
            $label = sprintf('%s — %s', $project->name, $project->owner->getDisplayName());
            $choices[$label] = $project->id->toString();
        }

        return $choices;
    }
}
