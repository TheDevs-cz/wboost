<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\TemplateGroup;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Entity\TemplateGroup;
use WBoost\Web\Query\GetTemplateGroupMembers;
use WBoost\Web\Services\Security\TemplateGroupVoter;
use WBoost\Web\Services\TemplateGroup\GroupFillRenderer;

/**
 * Live preview for ONE member variant of the group fill page: the fill form
 * is POSTed here (debounced, per visible dimension) and the full server
 * render comes back as a PNG — the same pixels the ZIP export will contain.
 */
final class TemplateGroupFillPreviewController extends AbstractController
{
    public function __construct(
        readonly private GetTemplateGroupMembers $members,
        readonly private GroupFillRenderer $groupFillRenderer,
    ) {
    }

    #[Route(path: '/template-group/{groupId}/fill-preview/{variantId}', name: 'template_group_fill_preview', methods: ['POST'])]
    #[IsGranted(TemplateGroupVoter::EDIT, 'group')]
    public function __invoke(
        #[MapEntity(id: 'groupId')]
        TemplateGroup $group,
        string $variantId,
        Request $request,
    ): Response {
        $variant = $this->resolveMemberVariant($group, $variantId);

        if ($variant === null) {
            throw $this->createNotFoundException('Variant does not belong to this group.');
        }

        $bytes = $this->groupFillRenderer->renderPng(
            $variant,
            $request->request->all('textValues'),
            $request->request->all('hiddenValues'),
            $request->request->all('images'),
        );

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Only variants actually belonging to the group are renderable here —
     * the same membership rule the group editor save enforces.
     */
    private function resolveMemberVariant(TemplateGroup $group, string $variantId): null|SocialNetworkTemplateVariant|CustomTemplateVariant
    {
        foreach ($this->members->socialVariants($group->id) as $variant) {
            if ($variant->id->toString() === $variantId) {
                return $variant;
            }
        }

        foreach ($this->members->customVariants($group->id) as $variant) {
            if ($variant->id->toString() === $variantId) {
                return $variant;
            }
        }

        return null;
    }
}
