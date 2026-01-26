<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Meal;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Meal;
use WBoost\Web\Message\Meal\DeleteMeal;
use WBoost\Web\Services\Security\MealVoter;

final class DeleteMealController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/meal/{mealId}/delete', name: 'delete_meal')]
    #[IsGranted(MealVoter::EDIT, 'meal')]
    public function __invoke(
        #[MapEntity(id: 'mealId')]
        Meal $meal,
    ): Response {
        $projectId = $meal->project->id;

        $this->bus->dispatch(
            new DeleteMeal($meal->id),
        );

        $this->addFlash('success', 'Jídlo bylo smazáno.');

        return $this->redirectToRoute('meals', [
            'projectId' => $projectId,
        ]);
    }
}
