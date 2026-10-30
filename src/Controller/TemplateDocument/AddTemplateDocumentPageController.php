<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\TemplateDocument;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Message\TemplateDocument\AddTemplateDocumentPage;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\TemplateDocumentVoter;
use WBoost\Web\Services\TemplateDocument\DispatchDocumentChange;

/**
 * "Nová stránka": a blank page of the document's size, straight into the
 * editor (with the document's page strip).
 */
final class AddTemplateDocumentPageController extends AbstractController
{
    public function __construct(
        readonly private DispatchDocumentChange $dispatch,
        readonly private ProvideIdentity $provideIdentity,
    ) {
    }

    #[Route(path: '/template-document/{documentId}/add-page', name: 'add_template_document_page', methods: ['POST'])]
    #[IsGranted(TemplateDocumentVoter::EDIT, 'document')]
    public function __invoke(
        #[MapEntity(id: 'documentId')]
        TemplateDocument $document,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid('template_document', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $name = trim($request->request->getString('name'));
        $name = mb_substr($name !== '' ? $name : sprintf('Strana %d', count($document->pages()) + 1), 0, 30);
        $variantId = $this->provideIdentity->next();

        $error = ($this->dispatch)(new AddTemplateDocumentPage(
            $document->id,
            $this->provideIdentity->next(),
            $this->provideIdentity->next(),
            $variantId,
            $name,
        ));

        if ($error !== null) {
            $this->addFlash('danger', $error->getMessage());

            return $this->redirectToRoute('template_document', ['documentId' => $document->id]);
        }

        return $this->redirectToRoute('template_variant_editor', ['variantId' => $variantId, 'document' => $document->id]);
    }
}
