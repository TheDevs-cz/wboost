<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\TemplateDocument;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Message\TemplateDocument\DeleteTemplateDocument;
use WBoost\Web\Services\Security\TemplateDocumentVoter;

/**
 * Two modes, posted by the delete dialog's buttons: `deletePages=0` dissolves
 * the document (pages return to the listing), `=1` deletes the page templates.
 */
final class DeleteTemplateDocumentController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/template-document/{documentId}/delete', name: 'delete_template_document', methods: ['POST'])]
    #[IsGranted(TemplateDocumentVoter::EDIT, 'document')]
    public function __invoke(
        #[MapEntity(id: 'documentId')]
        TemplateDocument $document,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid('template_document', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $projectId = $document->project->id;
        $deletePages = $request->request->getString('deletePages') === '1';

        $this->bus->dispatch(new DeleteTemplateDocument($document->id, $deletePages));

        $this->addFlash('success', $deletePages ? 'Dokument i jeho stránky smazány.' : 'Dokument zrušen, stránky se vrátily mezi šablony.');

        return $this->redirectToRoute('templates', ['projectId' => $projectId]);
    }
}
