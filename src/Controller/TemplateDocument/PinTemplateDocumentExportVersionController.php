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
use WBoost\Web\Controller\Template\PinTemplateExportVersionController;
use WBoost\Web\Entity\TemplateDocumentExportVersion;
use WBoost\Web\Message\TemplateDocument\PinTemplateDocumentExportVersion;
use WBoost\Web\Services\Security\TemplateDocumentExportVersionVoter;
use WBoost\Web\Services\TemplateDocument\DocumentExportHistoryView;

/**
 * Pin / unpin a stored DOCUMENT version (`pinned=1|0`) — the
 * {@see PinTemplateExportVersionController} contract (same CSRF id, Turbo
 * Stream from the fill page's dropdown, redirect from the history page).
 */
final class PinTemplateDocumentExportVersionController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private DocumentExportHistoryView $historyView,
    ) {
    }

    #[Route(path: '/document-export-version/{versionId}/pin', name: DocumentExportHistoryView::PIN_ROUTE, methods: ['POST'])]
    #[IsGranted(TemplateDocumentExportVersionVoter::MANAGE, 'version')]
    public function __invoke(
        #[MapEntity(id: 'versionId')]
        TemplateDocumentExportVersion $version,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid(PinTemplateExportVersionController::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $pinned = $request->request->getBoolean('pinned');

        $this->bus->dispatch(new PinTemplateDocumentExportVersion($version->id, $pinned));

        $stream = $this->historyView->respond($request, $version);
        if ($stream !== null) {
            return $stream;
        }

        $this->addFlash('success', $pinned ? 'Verze byla připnuta – zůstane nahoře a nikdy se nepromaže.' : 'Verze byla odepnuta.');

        return $this->redirect($this->historyView->redirectTarget($request, $version));
    }
}
