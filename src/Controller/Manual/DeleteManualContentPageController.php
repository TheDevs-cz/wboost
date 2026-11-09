<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\ManualContentPage;
use WBoost\Web\Message\Manual\DeleteManualContentPage;
use WBoost\Web\Services\Manual\ManualContentPageEditor;
use WBoost\Web\Services\Security\ManualContentPageVoter;

/**
 * Confirmed delete of a custom page (POST + CSRF from the confirm dialog).
 * The intro cannot be deleted — the handler refuses (400).
 */
final class DeleteManualContentPageController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/manual/stranka/{id}/smazat', name: 'delete_manual_content_page', methods: ['POST'])]
    #[IsGranted(ManualContentPageVoter::EDIT, 'page')]
    public function __invoke(ManualContentPage $page, Request $request): Response
    {
        $manualId = $page->manual->id;

        if (!$this->isCsrfTokenValid(ManualContentPageEditor::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            $this->addFlash('danger', 'Platnost formuláře vypršela. Zkuste to znovu.');
        } else {
            try {
                $this->bus->dispatch(new DeleteManualContentPage($page->id));
            } catch (HandlerFailedException $failed) {
                // The intro (IntroContentPageCannotBeDeleted → 400): no UI
                // offers it, so the real exception and its status are enough.
                throw $failed->getPrevious() ?? $failed;
            }

            $this->addFlash('success', 'Stránka smazána');
        }

        return $this->redirectToRoute('manual_mockup_pages', ['id' => $manualId]);
    }
}
