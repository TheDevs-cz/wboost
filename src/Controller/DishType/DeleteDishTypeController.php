<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\DishType;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\DishType;
use WBoost\Web\Message\DishType\DeleteDishType;
use WBoost\Web\Services\Security\DishTypeVoter;

final class DeleteDishTypeController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/dish-type/{dishTypeId}/delete', name: 'delete_dish_type', methods: ['POST'])]
    #[IsGranted(DishTypeVoter::EDIT, 'dishType')]
    public function __invoke(
        #[MapEntity(id: 'dishTypeId')]
        DishType $dishType,
    ): Response {
        $projectId = $dishType->project->id;

        $this->bus->dispatch(
            new DeleteDishType($dishType->id),
        );

        $this->addFlash('success', 'Druh jídla byl smazán.');

        return $this->redirectToRoute('dish_types', [
            'projectId' => $projectId,
        ]);
    }
}
