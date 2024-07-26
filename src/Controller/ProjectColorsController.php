<?php

declare(strict_types=1);

namespace WBoost\Web\Controller;

use WBoost\Web\FormData\ProjectImagesFormData;
use WBoost\Web\FormType\ProjectImagesFormType;
use WBoost\Web\Message\UpdateProjectImages;
use WBoost\Web\Repository\ProjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class ProjectColorsController extends AbstractController
{
    public function __construct(
        readonly private ProjectRepository $projectRepository,
    ) {
    }

    #[Route(path: '/project/{projectId}/colors', name: 'project_colors', methods: ['GET'])]
    public function __invoke(Request $request, string $projectId): Response
    {
        $project = $this->projectRepository->get($projectId);

        return $this->render('project_colors.html.twig', [
            'project' => $project,
        ]);
    }
}
