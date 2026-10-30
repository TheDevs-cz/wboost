<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\TemplateDocument;

use Ramsey\Uuid\Uuid;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Services\ReleaseSessionLock;
use WBoost\Web\Services\Security\TemplateDocumentVoter;
use WBoost\Web\Services\TemplateDocument\TemplateDocumentsFeature;
use WBoost\Web\Services\TemplateGroup\GroupFillPlaceholders;
use WBoost\Web\Services\TemplateGroup\GroupFillRenderer;
use WBoost\Web\Value\DocumentFillKeys;
use WBoost\Web\Value\RenderImageFormat;

/**
 * Live preview of ONE page of the document fill page — the twin of
 * {@see \WBoost\Web\Controller\TemplateGroup\TemplateGroupFillPreviewController}.
 * The whole fill form (FILL-KEY space) is POSTed here; only THIS page's
 * values are translated back to its real inputIds
 * ({@see DocumentFillKeys::valuesForPage()}) and the REAL variant is rendered
 * — the renderer never sees fill keys. WebP, like the group preview; the
 * export stays lossless.
 *
 * The client keeps one request in flight per page and at most two overall
 * (group_fill_controller's single-flight queue), and only renders pages that
 * are scrolled into view.
 */
final class TemplateDocumentFillPreviewController extends AbstractController
{
    public function __construct(
        readonly private GroupFillRenderer $groupFillRenderer,
        readonly private ReleaseSessionLock $releaseSessionLock,
        readonly private GroupFillPlaceholders $placeholders,
        readonly private TemplateDocumentsFeature $feature,
    ) {
    }

    #[Route(path: '/template-document/{documentId}/fill-preview/{pageId}', name: 'template_document_fill_preview', methods: ['POST'])]
    #[IsGranted(TemplateDocumentVoter::VIEW, 'document')]
    public function __invoke(
        #[MapEntity(id: 'documentId')]
        TemplateDocument $document,
        string $pageId,
        Request $request,
    ): Response {
        if (!$this->feature->isEnabled()) {
            throw $this->createNotFoundException();
        }

        // Only a page of THIS document is renderable here (pageId is the
        // TemplateDocumentPage id, never a template / variant id).
        $page = Uuid::isValid($pageId) ? $document->page(Uuid::fromString($pageId)) : null;

        if ($page === null) {
            throw $this->createNotFoundException('Page does not belong to this document.');
        }

        // The client renders pages in parallel; each is done with the
        // session once the voter has run.
        $this->releaseSessionLock->release($request);

        $values = DocumentFillKeys::forDocument($document)->valuesForPage($page, [
            'textValues' => $request->request->all('textValues'),
            'hiddenValues' => $request->request->all('hiddenValues'),
            'fontValues' => $request->request->all('fontValues'),
            'images' => $request->request->all('images'),
            'shapeColorValues' => $request->request->all('shapeColorValues'),
            'imagePlacements' => $request->request->all('imagePlacements'),
        ]);

        // ?base=1 — the echo BASE: this page's echo-capable texts transparent
        // (real inputIds: the base renders the real variant).
        $transparentTextInputIds = $request->query->getBoolean('base')
            ? $this->placeholders->echoCapableIds($page->variant)
            : [];

        $bytes = $this->groupFillRenderer->render(
            $page->variant,
            $values['textValues'],
            $values['hiddenValues'],
            $values['images'],
            $values['imagePlacements'],
            format: RenderImageFormat::Webp,
            transparentTextInputIds: $transparentTextInputIds,
            rawFontValues: $values['fontValues'],
            rawShapeColors: $values['shapeColorValues'],
        );

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => RenderImageFormat::Webp->contentType(),
            'Cache-Control' => 'no-store',
        ]);
    }
}
