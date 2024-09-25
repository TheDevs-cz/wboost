<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\ManualFont;
use WBoost\Web\Message\Manual\DeleteManualFont;
use WBoost\Web\Services\Security\ManualFontVoter;

final class DeleteManualFontController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/delete-manual-font/{id}', name: 'delete_manual_font')]
    #[IsGranted(ManualFontVoter::EDIT, 'manualFont')]
    public function __invoke(ManualFont $manualFont): Response
    {
        $this->bus->dispatch(
            new DeleteManualFont($manualFont->id),
        );

        $this->addFlash('success', 'Font smazán z manuálu');

        return $this->redirectToRoute('manual_fonts', [
            'id' => $manualFont->manual->id,
        ]);
    }
}
