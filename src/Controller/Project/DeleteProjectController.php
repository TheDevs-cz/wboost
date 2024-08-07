<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Project;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Message\Project\DeleteProject;
use WBoost\Web\Services\Security\ProjectVoter;

final class DeleteProjectController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/delete-project/{id}', name: 'delete_project')]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(Manual $project): Response
    {
        $this->bus->dispatch(
            new DeleteProject($project->id),
        );

        $this->addFlash('success', 'Projekt smazán');

        return $this->redirectToRoute('homepage');
    }
}
