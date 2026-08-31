<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\TemplateGroup;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateGroup;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Query\GetTemplateGroupMembers;
use WBoost\Web\Services\Security\TemplateGroupVoter;
use WBoost\Web\Services\TemplateGroup\GroupFillPlaceholders;
use WBoost\Web\Services\UploaderHelper;

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
        readonly private GetFonts $getFonts,
        readonly private UploaderHelper $uploaderHelper,
    ) {
    }

    #[Route(path: '/template-group/{groupId}/fill', name: 'template_group_fill', methods: ['GET'])]
    #[IsGranted(TemplateGroupVoter::VIEW, 'group')]
    public function __invoke(
        #[MapEntity(id: 'groupId')]
        TemplateGroup $group,
    ): Response {
        $memberVariants = $this->members->variants($group->id);

        $variants = [];

        foreach ($memberVariants as $variant) {
            $variants[] = [
                'variant' => $variant,
                'dimension_label' => sprintf('%s (%d×%d px)', $variant->dimension->label(), $variant->dimension->width(), $variant->dimension->height()),
                'width' => $variant->dimension->width(),
                'height' => $variant->dimension->height(),
                'preview_endpoint' => $this->generateUrl('template_group_fill_preview', [
                    'groupId' => $group->id,
                    'variantId' => $variant->id,
                ]),
                // Per-dimension PNG download — the preview card's heading
                // submits the same fill form here via `formaction`.
                'export_variant_url' => $this->generateUrl('template_group_export_variant', [
                    'groupId' => $group->id,
                    'variantId' => $variant->id,
                ]),
                // Per-dimension placeholder geometry: the client resolves the
                // shared relative placement into THIS dimension's pixels.
                'image_frames' => $this->placeholders->imageFrames($variant),
                // Client-side text echo (null = nothing echo-capable in this
                // dimension): eligibility is per-dimension, so each entry
                // carries its own designed objects + containers + rules.
                'echo' => $this->placeholders->echoData($variant),
            ];
        }

        $imageInputs = $this->placeholders->imageInputs($memberVariants, $group->project->id);

        // Slots the designer left adjustable are the only ones that get the
        // placement UI (and the only ones allowed to post a transform at all).
        $adjustableImageInputs = array_values(array_filter(
            $imageInputs,
            static fn (array $slot): bool => $slot['input']->allowMove || $slot['input']->allowResize || $slot['input']->allowRotate,
        ));

        return $this->render('template_group_fill.html.twig', [
            'project' => $group->project,
            'group' => $group,
            'menu_item' => 'templates',
            'variants' => $variants,
            'text_inputs' => $this->placeholders->textInputs($memberVariants),
            'image_inputs' => $imageInputs,
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
                    'echo' => $entry['echo'],
                ],
                $variants,
            ),
            // Project font faces for the echo canvases — same declarations +
            // explicit loads the single fill page ships, so client-drawn text
            // uses the exact glyphs the server render does.
            'font_faces' => $this->fontFaces($group),
            'has_echo' => array_reduce($variants, static fn (bool $carry, array $entry): bool => $carry || $entry['echo'] !== null, false),
            'export_url' => $this->generateUrl('template_group_export', ['groupId' => $group->id]),
        ]);
    }

    /**
     * @return list<array{family: string, url: string}>
     */
    private function fontFaces(TemplateGroup $group): array
    {
        $result = [];
        foreach ($this->getFonts->allForProject($group->project->id) as $font) {
            foreach ($font->faces as $fontFace) {
                $result[] = [
                    'family' => $font->faceFamily($fontFace),
                    'url' => $this->uploaderHelper->getPublicPath($fontFace->filePath),
                ];
            }
        }

        return $result;
    }
}
