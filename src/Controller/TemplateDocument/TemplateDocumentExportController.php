<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\TemplateDocument;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Services\Security\TemplateDocumentVoter;

/**
 * Exports the whole filled document (print PDF / screen PDF / PNG ZIP).
 * Placeholder until the multi-page PDF engine lands — sends the visitor back.
 */
final class TemplateDocumentExportController extends AbstractController
{
    #[Route(path: '/template-document/{documentId}/export', name: 'template_document_export', methods: ['GET', 'POST'])]
    #[IsGranted(TemplateDocumentVoter::VIEW, 'document')]
    public function __invoke(
        #[MapEntity(id: 'documentId')]
        TemplateDocument $document,
    ): Response {
        return $this->redirectToRoute('template_document', ['documentId' => $document->id]);
    }
}
