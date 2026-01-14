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
use WBoost\Web\Entity\DishType;
use WBoost\Web\FormData\DishTypeFormData;
use WBoost\Web\FormType\DishTypeFormType;
use WBoost\Web\Message\DishType\EditDishType;
use WBoost\Web\Services\Security\DishTypeVoter;

final class EditDishTypeController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/dish-type/{dishTypeId}/edit', name: 'edit_dish_type')]
    #[IsGranted(DishTypeVoter::EDIT, 'dishType')]
    public function __invoke(
        Request $request,
        #[MapEntity(id: 'dishTypeId')]
        DishType $dishType,
    ): Response {
        $data = new DishTypeFormData();
        $data->name = $dishType->name;

        $form = $this->createForm(DishTypeFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(
                new EditDishType(
                    $dishType->id,
                    $data->name,
                ),
            );

            $this->addFlash('success', 'Druh jídla byl upraven.');

            return $this->redirectToRoute('dish_types', [
                'projectId' => $dishType->project->id,
            ]);
        }

        return $this->render('edit_dish_type.html.twig', [
            'form' => $form,
            'dishType' => $dishType,
            'project' => $dishType->project,
        ]);
    }
}
