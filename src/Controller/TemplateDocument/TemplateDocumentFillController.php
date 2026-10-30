<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\TemplateDocument;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Services\Security\TemplateDocumentVoter;
use WBoost\Web\Services\TemplateDocument\DocumentFillPage;
use WBoost\Web\Services\TemplateDocument\TemplateDocumentsFeature;
use WBoost\Web\Services\UploaderHelper;

/**
 * The document fill & export page: every page of the document on ONE page,
 * stacked like Preview (continuous scroll, a thumbnail sidebar), filled
 * through the group fill machinery in FILL-KEY space — pages never share a
 * value except through an explicit shared field. Exports post the same form
 * to `template_document_export`. See docs/architecture/templates-documents.md
 * ("Fill page").
 */
final class TemplateDocumentFillController extends AbstractController
{
    public function __construct(
        readonly private DocumentFillPage $fillPage,
        readonly private GetFonts $getFonts,
        readonly private UploaderHelper $uploaderHelper,
        readonly private TemplateDocumentsFeature $feature,
    ) {
    }

    #[Route(path: '/template-document/{documentId}/fill', name: 'template_document_fill', methods: ['GET'])]
    #[IsGranted(TemplateDocumentVoter::VIEW, 'document')]
    public function __invoke(
        #[MapEntity(id: 'documentId')]
        TemplateDocument $document,
    ): Response {
        if (!$this->feature->isEnabled()) {
            throw $this->createNotFoundException();
        }

        return $this->render('template_document_fill.html.twig', [
            'project' => $document->project,
            'document' => $document,
            'menu_item' => 'templates',
            'fill' => $this->fillPage->build($document),
            'font_faces' => $this->fontFaces($document),
            'can_edit' => $this->isGranted(TemplateDocumentVoter::EDIT, $document),
        ]);
    }

    /**
     * @return list<array{family: string, url: string}>
     */
    private function fontFaces(TemplateDocument $document): array
    {
        $result = [];
        foreach ($this->getFonts->allForProject($document->project->id) as $font) {
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
