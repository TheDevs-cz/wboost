<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Ramsey\Uuid\Uuid;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Exceptions\InvalidManualContent;
use WBoost\Web\Message\Manual\AddManualContentPage;
use WBoost\Web\Services\Manual\ManualContentPageEditor;
use WBoost\Web\Services\Security\ManualVoter;
use WBoost\Web\Value\MockupPageLayout;

/**
 * "Přidat stránku → Obsahová stránka" dialog on the Stránky list (name +
 * layout): creates the empty custom page at the end of the list and opens
 * its editor.
 */
final class AddManualContentPageController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/manual/{manualId}/stranky/nova', name: 'add_manual_content_page', methods: ['POST'])]
    #[IsGranted(ManualVoter::EDIT, 'manual')]
    public function __invoke(
        #[MapEntity(id: 'manualId')]
        Manual $manual,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid(ManualContentPageEditor::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            $this->addFlash('danger', 'Platnost formuláře vypršela. Zkuste to znovu.');

            return $this->redirectToRoute('manual_mockup_pages', ['id' => $manual->id]);
        }

        $pageId = Uuid::uuid7();
        $layout = MockupPageLayout::tryFrom($request->request->getString('layout')) ?? MockupPageLayout::Layout7;

        try {
            $this->bus->dispatch(new AddManualContentPage($manual->id, $pageId, $request->request->getString('name'), $layout));
        } catch (HandlerFailedException $failed) {
            $real = $failed->getPrevious();

            if (!$real instanceof InvalidManualContent) {
                throw $failed;
            }

            $this->addFlash('danger', $real->getMessage());

            return $this->redirectToRoute('manual_mockup_pages', ['id' => $manual->id]);
        }

        return $this->redirectToRoute('manual_content_page', ['id' => $pageId]);
    }
}
