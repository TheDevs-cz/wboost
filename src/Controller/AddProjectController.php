<?php

declare(strict_types=1);

namespace WBoost\Web\Controller;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WBoost\Web\Entity\User;
use WBoost\Web\FormData\ProjectFormData;
use WBoost\Web\FormType\ProjectFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Message\Project\AddProject;

final class AddProjectController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/add-project', name: 'add_project', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        $data = new ProjectFormData();
        $form = $this->createForm(ProjectFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $projectId = Uuid::uuid7();

            $this->bus->dispatch(
                new AddProject(
                    $projectId,
                    $user->id,
                    $data->name,
                ),
            );

            return $this->redirectToRoute('project_logos', [
                'projectId' => $projectId->toString(),
            ]);
        }

        return $this->render('add_project.html.twig', [
            'add_project_form' => $form,
        ]);
    }
}
