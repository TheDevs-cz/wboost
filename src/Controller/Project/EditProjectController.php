<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Project;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\FormData\ProjectFormData;
use WBoost\Web\FormType\ProjectFormType;
use WBoost\Web\Message\Project\EditProject;
use WBoost\Web\Services\Security\ProjectVoter;

final class EditProjectController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,  
    ) {
    }
    
    #[Route(path: '/edit-project/{id}', name: 'edit_project')]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(Request $request, Project $project): Response
    {
        $data = new ProjectFormData();
        $data->name = $project->name;

        $form = $this->createForm(ProjectFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(
                new EditProject(
                    $project->id,
                    $data->name,
                ),  
            );

            return $this->redirectToRoute('project_logos', [
                'id' => $project->id->toString(),
            ]);
        }

        return $this->render('edit_project.html.twig', [
            'form' => $form,
            'project' => $project,
        ]);
    }
}
