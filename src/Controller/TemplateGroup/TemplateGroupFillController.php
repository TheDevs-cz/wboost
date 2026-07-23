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
                // Per-dimension placeholder geometry: the client resolves the
                // shared relative placement into THIS dimension's pixels.
                'image_frames' => $this->placeholders->imageFrames($variant),
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
                // Per-dimension placeholder geometry: the client resolves the
                // shared relative placement into THIS dimension's pixels.
                'image_frames' => $this->placeholders->imageFrames($variant),
            ];
        }

        $allVariants = [...$socialVariants, ...$customVariants];
        $imageInputs = $this->placeholders->imageInputs($allVariants, $group->project->id);

        // Slots the designer left adjustable are the only ones that get the
        // placement UI (and the only ones allowed to post a transform at all).
        $adjustableImageInputs = array_values(array_filter(
            $imageInputs,
            static fn (array $slot): bool => $slot['input']->allowMove || $slot['input']->allowResize || $slot['input']->allowRotate,
        ));

        return $this->render('template_group_fill.html.twig', [
            'project' => $group->project,
            'group' => $group,
            'menu_item' => 'template_groups',
            'variants' => $variants,
            'text_inputs' => $this->placeholders->textInputs($allVariants),
            'image_inputs' => $imageInputs,
            'adjustable_image_inputs' => $adjustableImageInputs,
            'placement_slots' => array_map(
                static fn (array $slot): array => [
                    'inputId' => $slot['input']->inputId,
                    'name' => $slot['input']->name,
                    'allowMove' => $slot['input']->allowMove,
                    'allowResize' => $slot['input']->allowResize,
                    'allowRotate' => $slot['input']->allowRotate,
                ],
                $adjustableImageInputs,
            ),
            'placement_variants' => array_map(
                static fn (array $entry): array => [
                    'variantId' => $entry['variant']->id->toString(),
                    'width' => $entry['width'],
                    'height' => $entry['height'],
                    'label' => $entry['dimension_label'],
                    'frames' => $entry['image_frames'],
                ],
                $variants,
            ),
            'export_url' => $this->generateUrl('template_group_export', ['groupId' => $group->id]),
        ]);
    }
}
