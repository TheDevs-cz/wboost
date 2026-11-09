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
use WBoost\Web\Exceptions\InvalidManualContent;
use WBoost\Web\Message\Manual\RenameManualContentPage;
use WBoost\Web\Services\Manual\ManualContentPageEditor;
use WBoost\Web\Services\Security\ManualContentPageVoter;

/**
 * "Přejmenovat" dialog of a custom page on the Stránky list.
 */
final class RenameManualContentPageController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/manual/stranka/{id}/prejmenovat', name: 'rename_manual_content_page', methods: ['POST'])]
    #[IsGranted(ManualContentPageVoter::EDIT, 'page')]
    public function __invoke(ManualContentPage $page, Request $request): Response
    {
        $back = $this->redirectToRoute('manual_mockup_pages', ['id' => $page->manual->id]);

        if (!$this->isCsrfTokenValid(ManualContentPageEditor::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            $this->addFlash('danger', 'Platnost formuláře vypršela. Zkuste to znovu.');

            return $back;
        }

        try {
            $this->bus->dispatch(new RenameManualContentPage($page->id, $request->request->getString('name')));
        } catch (HandlerFailedException $failed) {
            $real = $failed->getPrevious();

            if (!$real instanceof InvalidManualContent) {
                throw $failed;
            }

            $this->addFlash('danger', $real->getMessage());

            return $back;
        }

        $this->addFlash('success', 'Stránka přejmenována');

        return $back;
    }
}
