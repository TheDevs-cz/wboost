<?php

declare(strict_types=1);

namespace WBoost\Web\FormType;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use WBoost\Web\FormData\EditUserFormData;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Value\UserRoleChoice;

/**
 * @extends AbstractType<EditUserFormData>
 */
final class EditUserFormType extends AbstractType
{
    /**
     * Keyed by label, like {@see UserRoleChoice::CHOICES}. Export and design
     * imply read ({@see McpScope::grants()}), so ticking one is enough.
     */
    private const array MCP_SCOPE_CHOICES = [
        'Prohlížet projekty a šablony (templates:read)' => McpScope::TemplatesRead->value,
        'Stahovat hotové exporty (templates:export)' => McpScope::TemplatesExport->value,
        'Vytvářet a přepisovat šablony (templates:design)' => McpScope::TemplatesDesign->value,
        'Nahrávat obrázky do galerie (gallery:write)' => McpScope::GalleryWrite->value,
    ];

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
            ])
            ->add('mcpScopes', ChoiceType::class, [
                'label' => 'Oprávnění AI aplikací (přihlášení přes OAuth)',
                'help' => 'Nejvýš tohle může AI aplikace připojená přihlášením tohoto uživatele získat. '
                    . 'Tokeny vytvořené administrátorem mají vlastní oprávnění. Zúžení platí okamžitě.',
                'choices' => self::MCP_SCOPE_CHOICES,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EditUserFormData::class,
        ]);
    }
}
