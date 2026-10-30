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
use WBoost\Web\Message\Template\CopyTemplate;
use WBoost\Web\Message\TemplateDocument\InsertTemplateDocumentPage;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\TemplateDocumentVoter;
use WBoost\Web\Services\TemplateDocument\DispatchDocumentChange;

/**
 * "Duplikovat stránku": copies the page template (the ordinary Duplikovat) and
 * attaches the copy of the page's OWN variant right after it. The copy keeps
 * the canvas verbatim, so its inputIds collide with the original's — by
 * design: pages never share values implicitly.
 */
final class DuplicateTemplateDocumentPageController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private DispatchDocumentChange $dispatch,
        readonly private ProvideIdentity $provideIdentity,
    ) {
    }

    #[Route(path: '/template-document/{documentId}/page/{templateId}/duplicate', name: 'duplicate_template_document_page', methods: ['POST'])]
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

        $page = $document->pageOfTemplate($template->id);

        if ($page === null) {
            throw $this->createNotFoundException();
        }

        $newTemplateId = $this->provideIdentity->next();
        $newVariantId = $this->provideIdentity->next();

        $this->bus->dispatch(new CopyTemplate($template->id, $newTemplateId, [$page->variant->id->toString() => $newVariantId]));

        $error = ($this->dispatch)(new InsertTemplateDocumentPage($document->id, $this->provideIdentity->next(), $newVariantId, $page->position + 1));

        if ($error !== null) {
            $this->addFlash('danger', $error->getMessage());
        }

        return $this->redirectToRoute('template_document', ['documentId' => $document->id, 'page' => $newTemplateId]);
    }
}
