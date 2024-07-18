<?php

declare(strict_types=1);

namespace BrandManuals\Web\Controller;

use BrandManuals\Web\FormData\ProjectImagesFormData;
use BrandManuals\Web\FormType\ProjectImagesFormType;
use BrandManuals\Web\Repository\ProjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProjectDetailController extends AbstractController
{
    public function __construct(
        readonly private ProjectRepository $projectRepository,
    ) {
    }

    #[Route(path: '/project-detail/{projectId}', name: 'project_detail', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, string $projectId): Response
    {
        $project = $this->projectRepository->get($projectId);

        $data = new ProjectImagesFormData();

        $form = $this->createForm(ProjectImagesFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            return $this->redirectToRoute('project_detail', [
                'projectId' => $project->id->toString(),
            ]);
        }

        return $this->render('project_detail.html.twig', [
            'project' => $project,
            'project_images_form' => $form,
        ]);
    }
}
