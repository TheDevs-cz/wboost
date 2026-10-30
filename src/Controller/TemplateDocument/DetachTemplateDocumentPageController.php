<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\TemplateDocument;

use Ramsey\Uuid\UuidInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Message\TemplateDocument\SetTemplateDocumentPages;
use WBoost\Web\Services\Security\TemplateDocumentVoter;
use WBoost\Web\Services\TemplateDocument\DispatchDocumentChange;

/**
 * "Odpojit": the page leaves the document; its template returns to the
 * listing untouched.
 */
final class DetachTemplateDocumentPageController extends AbstractController
{
    public function __construct(
        readonly private DispatchDocumentChange $dispatch,
    ) {
    }

    #[Route(path: '/template-document/{documentId}/page/{templateId}/detach', name: 'detach_template_document_page', methods: ['POST'])]
    #[IsGranted(TemplateDocumentVoter::EDIT, 'document')]
    public function __invoke(
        #[MapEntity(id: 'documentId')]
        TemplateDocument $document,
        #[MapEntity(id: 'templateId')]
        Template $template,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid('template_document', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($document->pageOfTemplate($template->id) === null) {
            throw $this->createNotFoundException();
        }

        $remaining = array_values(array_filter(
            array_map(static fn ($page): UuidInterface => $page->template->id, $document->pages()),
            static fn (UuidInterface $id): bool => !$id->equals($template->id),
        ));

        $error = ($this->dispatch)(new SetTemplateDocumentPages($document->id, $remaining));

        if ($error !== null) {
            $this->addFlash('danger', $error->getMessage());
        } else {
            $this->addFlash('success', sprintf('Stránka „%s" odpojena — najdete ji mezi šablonami.', $template->name));
        }

        return $this->redirectToRoute('template_document', ['documentId' => $document->id]);
    }
}
