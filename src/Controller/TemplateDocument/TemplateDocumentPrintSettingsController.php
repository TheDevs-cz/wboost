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
use WBoost\Web\FormData\TemplateVariantFormData;
use WBoost\Web\Message\TemplateDocument\EditTemplateDocumentPrintSettings;
use WBoost\Web\Services\Security\TemplateDocumentVoter;

/**
 * "Tiskové nastavení dokumentu": bleed + crop marks for the document AND every
 * page variant at once.
 */
final class TemplateDocumentPrintSettingsController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/template-document/{documentId}/print-settings', name: 'template_document_print_settings', methods: ['POST'])]
    #[IsGranted(TemplateDocumentVoter::EDIT, 'document')]
    public function __invoke(
        #[MapEntity(id: 'documentId')]
        TemplateDocument $document,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid('template_document', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!$document->dimension->isPrintFormat()) {
            throw $this->createNotFoundException();
        }

        $bleed = (float) str_replace(',', '.', $request->request->getString('bleed', '0'));
        $bleed = max(0.0, min((float) TemplateVariantFormData::MAX_BLEED_MM, $bleed));

        $this->bus->dispatch(new EditTemplateDocumentPrintSettings($document->id, $bleed, $request->request->getBoolean('cropMarks')));

        $this->addFlash('success', 'Tiskové nastavení uloženo pro všechny stránky.');

        return $this->redirectToRoute('template_document', ['documentId' => $document->id]);
    }
}
