<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\ManualMockupPage;
use WBoost\Web\Message\Manual\DeleteManualMockupPage;
use WBoost\Web\Services\Security\ManualMockupPageVoter;

final class DeleteManualMockupPageController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/delete-manual-mockup-page/{id}', name: 'delete_manual_mockup_page')]
    #[IsGranted(ManualMockupPageVoter::EDIT, 'page')]
    public function __invoke(ManualMockupPage $page): Response
    {
        $this->bus->dispatch(
            new DeleteManualMockupPage($page->id),
        );

        $this->addFlash('success', 'Stránka smazána');

        return $this->redirectToRoute('manual_mockup_pages', [
            'id' => $page->manual->id,
        ]);
    }
}
