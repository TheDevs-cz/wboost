<?php

declare(strict_types=1);

namespace BrandManuals\Web\Controller;

use BrandManuals\Web\Repository\ProjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DeleteProjectController extends AbstractController
{
    public function __construct(
        readonly private ProjectRepository $projectRepository,
    ) {
    }

    #[Route(path: '/delete-project/{projectId}', name: 'delete_project', methods: ['GET'])]
    public function __invoke(string $projectId): Response
    {
        $project = $this->projectRepository->get($projectId);

        $this->projectRepository->remove($project);

        $this->addFlash('success', 'Projekt smazán z povrchu zemského');

        return $this->redirectToRoute('homepage');
    }
}
