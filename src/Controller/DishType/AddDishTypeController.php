<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\DishType;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\FormData\DishTypeFormData;
use WBoost\Web\FormType\DishTypeFormType;
use WBoost\Web\Message\DishType\AddDishType;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\ProjectVoter;

final class AddDishTypeController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
    ) {
    }

    #[Route(path: '/project/{projectId}/dish-types/add', name: 'add_dish_type')]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(
        Request $request,
        #[MapEntity(id: 'projectId')]
        Project $project,
    ): Response {
        $data = new DishTypeFormData();
        $form = $this->createForm(DishTypeFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $dishTypeId = $this->provideIdentity->next();

            $this->bus->dispatch(
                new AddDishType(
                    $project->id,
                    $dishTypeId,
                    $data->name,
                ),
            );

            $this->addFlash('success', 'Druh jídla byl vytvořen.');

            return $this->redirectToRoute('dish_types', [
                'projectId' => $project->id,
            ]);
        }

        return $this->render('add_dish_type.html.twig', [
            'form' => $form,
            'project' => $project,
        ]);
    }
}
