<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\TemplateGroup;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Entity\TemplateGroup;
use WBoost\Web\Query\GetTemplateGroupMembers;
use WBoost\Web\Services\ReleaseSessionLock;
use WBoost\Web\Services\Security\TemplateGroupVoter;
use WBoost\Web\Services\TemplateGroup\GroupFillPlaceholders;
use WBoost\Web\Services\TemplateGroup\GroupFillRenderer;
use WBoost\Web\Value\RenderImageFormat;

/**
 * Live preview for ONE member variant of the group fill page: the fill form
 * is POSTed here (debounced, per visible dimension) and the full server
 * render comes back as a WebP — the same LAYOUT the ZIP export will contain,
 * but no longer the same bytes: this is an on-screen preview, so it takes the
 * faster/smaller lossy encode while the export stays lossless PNG.
 *
 * Safe to change unilaterally because the JS consumer is format-agnostic —
 * group_fill_controller.js does `response.blob()` + `createObjectURL()` and
 * inherits whatever Content-Type this sets.
 */
final class TemplateGroupFillPreviewController extends AbstractController
{
    public function __construct(
        readonly private GetTemplateGroupMembers $members,
        readonly private GroupFillRenderer $groupFillRenderer,
        readonly private ReleaseSessionLock $releaseSessionLock,
        readonly private GroupFillPlaceholders $placeholders,
    ) {
    }

    #[Route(path: '/template-group/{groupId}/fill-preview/{variantId}', name: 'template_group_fill_preview', methods: ['POST'])]
    #[IsGranted(TemplateGroupVoter::VIEW, 'group')]
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

        // The debounced client fires one of these POSTs PER DIMENSION in
        // parallel; each is done with the session once the voter has run.
        $this->releaseSessionLock->release($request);

        // ?base=1 — the echo BASE: the same render with this dimension's
        // echo-capable texts transparent, fetched lazily by the client the
        // first time the user types so the local text layer has something to
        // paint over. Exports never take this path.
        $transparentTextInputIds = $request->query->getBoolean('base')
            ? $this->placeholders->echoCapableIds($variant)
            : [];

        $bytes = $this->groupFillRenderer->render(
            $variant,
            $request->request->all('textValues'),
            $request->request->all('hiddenValues'),
            $request->request->all('images'),
            $request->request->all('imagePlacements'),
            format: RenderImageFormat::Webp,
            transparentTextInputIds: $transparentTextInputIds,
            rawFontValues: $request->request->all('fontValues'),
        );

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => RenderImageFormat::Webp->contentType(),
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Only variants actually belonging to the group are renderable here —
     * the same membership rule the group editor save enforces.
     */
    private function resolveMemberVariant(TemplateGroup $group, string $variantId): null|TemplateVariant
    {
        foreach ($this->members->variants($group->id) as $variant) {
            if ($variant->id->toString() === $variantId) {
                return $variant;
            }
        }

        return null;
    }
}
