<?php

declare(strict_types=1);

namespace BrandManuals\Web\Controller;

use BrandManuals\Web\FormData\ProjectFormData;
use BrandManuals\Web\FormType\ProjectFormType;
use BrandManuals\Web\Repository\ProjectRepository;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EditProjectController extends AbstractController
{
    public function __construct(
        readonly private ProjectRepository $projectRepository,
        readonly private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/edit-project/{projectId}', name: 'edit_project', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, string $projectId): Response
    {
        $project = $this->projectRepository->get($projectId);

        $data = new ProjectFormData();
        $data->name = $project->name;

        $form = $this->createForm(ProjectFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $project->edit($data->name);
            $this->entityManager->flush();

            return $this->redirectToRoute('project_logos', [
                'projectId' => $project->id->toString(),
            ]);
        }

        return $this->render('edit_project.html.twig', [
            'edit_project_form' => $form,
            'project' => $project,
        ]);
    }
}
