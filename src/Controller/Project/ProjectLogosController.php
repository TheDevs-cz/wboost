<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Project;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Manual;
use WBoost\Web\FormData\ProjectImagesFormData;
use WBoost\Web\FormType\ProjectImagesFormType;
use WBoost\Web\Message\Project\UpdateProjectImages;
use WBoost\Web\Services\Security\ProjectVoter;

final class ProjectLogosController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/project/{id}/logos', name: 'project_logos')]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(
        Request $request,
        Manual $project,
    ): Response {
        $data = new ProjectImagesFormData();

        $form = $this->createForm(ProjectImagesFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(
                UpdateProjectImages::fromFormData($project->id, $data),
            );

            return $this->redirectToRoute('project_logos', [
                'id' => $project->id->toString(),
            ]);
        }

        return $this->render('project_logos.html.twig', [
            'project' => $project,
            'project_images_form' => $form,
        ]);
    }
}
