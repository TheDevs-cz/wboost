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
use WBoost\Web\Controller\Template\RenameTemplateExportVersionController;
use WBoost\Web\Entity\TemplateDocumentExportVersion;
use WBoost\Web\Message\TemplateDocument\RenameTemplateDocumentExportVersion;
use WBoost\Web\Services\Security\TemplateDocumentExportVersionVoter;
use WBoost\Web\Services\TemplateDocument\DocumentExportHistoryView;

/**
 * Name a stored DOCUMENT version (blank = unname) — the
 * {@see RenameTemplateExportVersionController} contract.
 */
final class RenameTemplateDocumentExportVersionController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private DocumentExportHistoryView $historyView,
    ) {
    }

    #[Route(path: '/document-export-version/{versionId}/rename', name: DocumentExportHistoryView::RENAME_ROUTE, methods: ['POST'])]
    #[IsGranted(TemplateDocumentExportVersionVoter::MANAGE, 'version')]
    public function __invoke(
        #[MapEntity(id: 'versionId')]
        TemplateDocumentExportVersion $version,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid(RenameTemplateExportVersionController::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $name = trim($request->request->getString('name'));

        $this->bus->dispatch(new RenameTemplateDocumentExportVersion($version->id, $name === '' ? null : $name));

        $stream = $this->historyView->respond($request, $version);
        if ($stream !== null) {
            return $stream;
        }

        $this->addFlash('success', $name === '' ? 'Název verze byl odebrán.' : 'Verze byla pojmenována.');

        return $this->redirect($this->historyView->redirectTarget($request, $version));
    }
}
