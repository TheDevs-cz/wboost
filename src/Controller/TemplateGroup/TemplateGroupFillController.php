<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\TemplateGroup;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateGroup;
use WBoost\Web\Query\GetTemplateGroupMembers;
use WBoost\Web\Services\Security\TemplateGroupVoter;
use WBoost\Web\Services\TemplateGroup\GroupFillPlaceholders;

/**
 * The group fill & export page: one unified form field per distinct
 * placeholder (joined across member variants by the shared inputId UUID),
 * a live preview per dimension, and a single "download everything as ZIP"
 * action ({@see TemplateGroupExportController}).
 */
final class TemplateGroupFillController extends AbstractController
{
    public function __construct(
        readonly private GetTemplateGroupMembers $members,
        readonly private GroupFillPlaceholders $placeholders,
    ) {
    }

    #[Route(path: '/template-group/{groupId}/fill', name: 'template_group_fill', methods: ['GET'])]
    #[IsGranted(TemplateGroupVoter::EDIT, 'group')]
    public function __invoke(
        #[MapEntity(id: 'groupId')]
        TemplateGroup $group,
    ): Response {
        $socialVariants = $this->members->socialVariants($group->id);
        $customVariants = $this->members->customVariants($group->id);

        $variants = [];

        foreach ($socialVariants as $variant) {
            $variants[] = [
                'variant' => $variant,
                'module_label' => 'Sociální sítě',
                'dimension_label' => sprintf('%s (%d×%d px)', $variant->dimension->value, $variant->dimension->width(), $variant->dimension->height()),
                'width' => $variant->dimension->width(),
                'height' => $variant->dimension->height(),
                'preview_endpoint' => $this->generateUrl('template_group_fill_preview', [
                    'groupId' => $group->id,
                    'variantId' => $variant->id,
                ]),
            ];
        }

        foreach ($customVariants as $variant) {
            $variants[] = [
                'variant' => $variant,
                'module_label' => 'Šablony',
                'dimension_label' => sprintf('%s (%d×%d px)', $variant->dimension->label(), $variant->dimension->width(), $variant->dimension->height()),
                'width' => $variant->dimension->width(),
                'height' => $variant->dimension->height(),
                'preview_endpoint' => $this->generateUrl('template_group_fill_preview', [
                    'groupId' => $group->id,
                    'variantId' => $variant->id,
                ]),
            ];
        }

        $allVariants = [...$socialVariants, ...$customVariants];

        return $this->render('template_group_fill.html.twig', [
            'project' => $group->project,
            'group' => $group,
            'menu_item' => 'template_groups',
            'variants' => $variants,
            'text_inputs' => $this->placeholders->textInputs($allVariants),
            'image_inputs' => $this->placeholders->imageInputs($allVariants, $group->project->id),
            'export_url' => $this->generateUrl('template_group_export', ['groupId' => $group->id]),
        ]);
    }
}
