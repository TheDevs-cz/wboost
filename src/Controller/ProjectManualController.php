<?php

declare(strict_types=1);

namespace BrandManuals\Web\Controller;

use BrandManuals\Web\Repository\ProjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProjectManualController extends AbstractController
{
    public function __construct(
        readonly private ProjectRepository $projectRepository,
    ) {
    }

    #[Route(path: '/project/{projectId}/manual', name: 'project_manual', methods: ['GET'])]
    public function __invoke(Request $request, string $projectId): Response
    {
        $project = $this->projectRepository->get($projectId);

        return $this->render('project_manual.html.twig', [
            'project' => $project,
        ]);
    }
}