<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Message\Manual\DeleteManual;
use WBoost\Web\Services\Security\ManualVoter;

final class DeleteManualController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/delete-manual/{id}', name: 'delete_manual')]
    #[IsGranted(ManualVoter::EDIT, 'manual')]
    public function __invoke(Manual $manual): Response
    {
        $this->bus->dispatch(
            new DeleteManual($manual->id),
        );

        $this->addFlash('success', 'Manuál smazán');

        return $this->redirectToRoute('manuals_list', [
            'id' => $manual->project->id,
        ]);
    }
}
