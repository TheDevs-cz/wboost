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

final class ProjectLogosController extends AbstractController
{
    public function __construct(
        readonly private ProjectRepository $projectRepository,
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/project/{projectId}/logos', name: 'project_logos', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, string $projectId): Response
    {
        $project = $this->projectRepository->get($projectId);

        $data = new ProjectImagesFormData();

        $form = $this->createForm(ProjectImagesFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(
                UpdateProjectImages::fromFormData($projectId, $data),
            );

            return $this->redirectToRoute('project_logos', [
                'projectId' => $project->id->toString(),
            ]);
        }

        return $this->render('project_logos.html.twig', [
            'project' => $project,
            'project_images_form' => $form,
        ]);
    }
}
