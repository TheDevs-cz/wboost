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
use WBoost\Web\Message\TemplateDocument\SetTemplateDocumentFields;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\TemplateDocumentVoter;
use WBoost\Web\Services\TemplateDocument\DispatchDocumentChange;
use WBoost\Web\Services\TemplateDocument\DocumentFieldsView;
use WBoost\Web\Value\DocumentFieldBinding;
use WBoost\Web\Value\DocumentFieldDefinition;
use WBoost\Web\Value\DocumentFieldKind;

/**
 * "Společná pole" on the document page: add / edit / delete ONE shared field
 * (the modal, or a one-click suggestion). The handler takes the WHOLE list
 * (full replace, ids kept), so this rebuilds it from the stored fields —
 * stale bindings dropped, see {@see DocumentFieldsView::currentDefinitions()}
 * — with the one change applied.
 *
 * Form: `fieldId` ("" = new), `name`, `kind` (text|image),
 * `bindings[<templateId>]` = inputId ("" = nepropojeno), `delete` = 1 to drop.
 */
final class TemplateDocumentFieldsController extends AbstractController
{
    public function __construct(
        readonly private DispatchDocumentChange $dispatch,
        readonly private DocumentFieldsView $fieldsView,
        readonly private ProvideIdentity $provideIdentity,
    ) {
    }

    #[Route(path: '/template-document/{documentId}/fields', name: 'template_document_fields', methods: ['POST'])]
    #[IsGranted(TemplateDocumentVoter::EDIT, 'document')]
    public function __invoke(
        #[MapEntity(id: 'documentId')]
        TemplateDocument $document,
        Request $request,
    ): Response {

        if (!$this->isCsrfTokenValid('template_document', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $definitions = $this->fieldsView->currentDefinitions($document);
        $fieldId = $request->request->getString('fieldId');
        $existing = null;

        foreach ($definitions as $index => $definition) {
            if ($definition->id->toString() === $fieldId) {
                $existing = $index;
            }
        }

        if ($fieldId !== '' && $existing === null) {
            throw $this->createNotFoundException('Field does not belong to this document.');
        }

        if ($request->request->getBoolean('delete')) {
            if ($existing !== null) {
                array_splice($definitions, $existing, 1);
            }

            return $this->apply($document, $definitions, 'Společné pole odstraněno — stránky se teď vyplňují každá zvlášť.');
        }

        $kind = DocumentFieldKind::tryFrom($request->request->getString('kind')) ?? DocumentFieldKind::Text;
        $bindings = [];

        foreach ($request->request->all('bindings') as $templateId => $inputId) {
            if (is_string($inputId) && $inputId !== '' && Uuid::isValid((string) $templateId)) {
                $bindings[] = new DocumentFieldBinding((string) $templateId, $inputId);
            }
        }

        $definition = new DocumentFieldDefinition(
            $existing !== null ? $definitions[$existing]->id : $this->provideIdentity->next(),
            mb_substr(trim($request->request->getString('name')), 0, 100),
            $kind,
            $bindings,
        );

        if ($existing !== null) {
            $definitions[$existing] = $definition;
        } else {
            $definitions[] = $definition;
        }

        return $this->apply($document, array_values($definitions), sprintf('Společné pole „%s" uloženo.', $definition->name));
    }

    /**
     * @param list<DocumentFieldDefinition> $definitions
     */
    private function apply(TemplateDocument $document, array $definitions, string $success): Response
    {
        $error = ($this->dispatch)(new SetTemplateDocumentFields($document->id, $definitions));

        if ($error !== null) {
            $this->addFlash('danger', $error->getMessage());
        } else {
            $this->addFlash('success', $success);
        }

        return $this->redirectToRoute('template_document', ['documentId' => $document->id, '_fragment' => 'shared-fields']);
    }
}
