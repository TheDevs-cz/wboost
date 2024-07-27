<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Project;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WBoost\Web\FormData\ProjectFormData;
use WBoost\Web\FormType\ProjectFormType;
use WBoost\Web\Message\Project\AddProject;
use WBoost\Web\Services\ProvideIdentity;

final class AddProjectController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
    ) {
    }

    #[Route(path: '/add-project', name: 'add_project')]
    public function __invoke(Request $request, #[CurrentUser] UserInterface $user): Response
    {
        $data = new ProjectFormData();
        $form = $this->createForm(ProjectFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $projectId = $this->provideIdentity->next();

            $this->bus->dispatch(
                new AddProject(
                    $projectId,
                    $user->getUserIdentifier(),
                    $data->name,
                ),
            );

            return $this->redirectToRoute('project_logos', [
                'id' => $projectId,
            ]);
        }

        return $this->render('add_project.html.twig', [
            'form' => $form,
        ]);
    }
}
