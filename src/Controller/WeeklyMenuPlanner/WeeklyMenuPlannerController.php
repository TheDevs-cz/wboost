<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\WeeklyMenuPlanner;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Query\GetMeals;
use WBoost\Web\Repository\WeeklyMenuRepository;
use WBoost\Web\Services\Security\WeeklyMenuVoter;

final class WeeklyMenuPlannerController extends AbstractController
{
    public function __construct(
        readonly private GetMeals $getMeals,
        readonly private WeeklyMenuRepository $weeklyMenuRepository,
    ) {
    }

    #[Route(path: '/weekly-menu/{menuId}/planner', name: 'weekly_menu_planner')]
    public function __invoke(string $menuId): Response
    {
        $menu = $this->weeklyMenuRepository->getWithFullTree(Uuid::fromString($menuId));

        $this->denyAccessUnlessGranted(WeeklyMenuVoter::EDIT, $menu);

        return $this->render('weekly_menu_planner.html.twig', [
            'menu' => $menu,
            'project' => $menu->project,
            'meals' => $this->getMeals->allForProject($menu->project->id),
        ]);
    }
}
