<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\WeeklyMenuPlanner;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\WeeklyMenu;
use WBoost\Web\Query\GetMeals;
use WBoost\Web\Services\Security\WeeklyMenuVoter;

final class WeeklyMenuPlannerController extends AbstractController
{
    public function __construct(
        readonly private GetMeals $getMeals,
    ) {
    }

    #[Route(path: '/weekly-menu/{menuId}/planner', name: 'weekly_menu_planner')]
    #[IsGranted(WeeklyMenuVoter::EDIT, 'menu')]
    public function __invoke(
        #[MapEntity(id: 'menuId')]
        WeeklyMenu $menu,
    ): Response {
        return $this->render('weekly_menu_planner.html.twig', [
            'menu' => $menu,
            'project' => $menu->project,
            'meals' => $this->getMeals->allForProject($menu->project->id),
        ]);
    }
}
