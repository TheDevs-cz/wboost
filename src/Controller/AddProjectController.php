<?php

declare(strict_types=1);

namespace WBoost\Web\Controller;

use WBoost\Web\Entity\Project;
use WBoost\Web\FormData\ProjectFormData;
use WBoost\Web\FormType\ProjectFormType;
use WBoost\Web\Repository\ProjectRepository;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AddProjectController extends AbstractController
{
    public function __construct(
        readonly private ProjectRepository $projectRepository,
    ) {
    }

    #[Route(path: '/add-project', name: 'add_project', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $data = new ProjectFormData();
        $form = $this->createForm(ProjectFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $project = new Project(
                Uuid::uuid7(),
                $data->name,
                new \DateTimeImmutable(),
            );

            $this->projectRepository->save($project);

            return $this->redirectToRoute('project_logos', [
                'projectId' => $project->id->toString(),
            ]);
        }

        return $this->render('add_project.html.twig', [
            'add_project_form' => $form,
        ]);
    }
}
