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
use WBoost\Web\Message\TemplateDocument\SetTemplateDocumentPages;
use WBoost\Web\Services\Security\TemplateDocumentVoter;
use WBoost\Web\Services\TemplateDocument\DispatchDocumentChange;

/**
 * "Připojit existující": appends the ticked templates after the current pages
 * (in the dialog's order).
 */
final class AttachTemplateDocumentPagesController extends AbstractController
{
    public function __construct(
        readonly private DispatchDocumentChange $dispatch,
    ) {
    }

    #[Route(path: '/template-document/{documentId}/attach', name: 'attach_template_document_pages', methods: ['POST'])]
    #[IsGranted(TemplateDocumentVoter::EDIT, 'document')]
    public function __invoke(
        #[MapEntity(id: 'documentId')]
        TemplateDocument $document,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid('template_document', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $ids = array_map(static fn ($page) => $page->template->id, $document->pages());
        $added = 0;

        foreach ($request->request->all('templateIds') as $id) {
            if (is_string($id) && Uuid::isValid($id) && $document->pageOfTemplate(Uuid::fromString($id)) === null) {
                $ids[] = Uuid::fromString($id);
                $added++;
            }
        }

        $error = ($this->dispatch)(new SetTemplateDocumentPages($document->id, $ids));

        if ($error !== null) {
            $this->addFlash('danger', $error->getMessage());
        } elseif ($added > 0) {
            $this->addFlash('success', $added === 1 ? 'Stránka připojena.' : sprintf('Připojeno stran: %d.', $added));
        }

        return $this->redirectToRoute('template_document', ['documentId' => $document->id]);
    }
}
