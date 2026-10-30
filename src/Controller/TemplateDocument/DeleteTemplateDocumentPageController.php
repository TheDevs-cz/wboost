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
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Message\Template\DeleteTemplate;
use WBoost\Web\Message\TemplateDocument\SetTemplateDocumentPages;
use WBoost\Web\Services\Security\TemplateDocumentVoter;

/**
 * "Smazat stránku": deletes the page's template for good (variants and all).
 * The page is detached first, so its shared-field bindings go and the
 * remaining pages close the gap.
 */
final class DeleteTemplateDocumentPageController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/template-document/{documentId}/page/{templateId}/delete', name: 'delete_template_document_page', methods: ['POST'])]
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

        $remaining = [];
        foreach ($document->pages() as $page) {
            if (!$page->template->id->equals($template->id)) {
                $remaining[] = $page->template->id;
            }
        }

        $name = $template->name;
        $this->bus->dispatch(new SetTemplateDocumentPages($document->id, $remaining));
        $this->bus->dispatch(new DeleteTemplate($template->id));

        $this->addFlash('success', sprintf('Stránka „%s" smazána.', $name));

        return $this->redirectToRoute('template_document', ['documentId' => $document->id]);
    }
}
