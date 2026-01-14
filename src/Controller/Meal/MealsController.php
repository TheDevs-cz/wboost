<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Meal;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Message\DishType\SeedDefaultDishTypes;
use WBoost\Web\Query\GetDishTypes;
use WBoost\Web\Query\GetMeals;
use WBoost\Web\Services\Security\ProjectVoter;

final class MealsController extends AbstractController
{
    public function __construct(
        readonly private GetMeals $getMeals,
        readonly private GetDishTypes $getDishTypes,
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/project/{projectId}/meals', name: 'meals')]
    #[IsGranted(ProjectVoter::VIEW, 'project')]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
    ): Response {
        // Seed default dish types if none exist
        if ($this->getDishTypes->countForProject($project->id) === 0) {
            $this->bus->dispatch(new SeedDefaultDishTypes($project->id));
        }

        return $this->render('meals.html.twig', [
            'project' => $project,
            'meals' => $this->getMeals->allForProject($project->id),
        ]);
    }
}
