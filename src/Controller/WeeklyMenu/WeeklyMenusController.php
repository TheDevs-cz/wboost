<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\WeeklyMenu;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Query\GetWeeklyMenus;
use WBoost\Web\Services\Security\ProjectVoter;

final class WeeklyMenusController extends AbstractController
{
    public function __construct(
        readonly private GetWeeklyMenus $getWeeklyMenus,
    ) {
    }

    #[Route(path: '/project/{projectId}/weekly-menus', name: 'weekly_menus')]
    #[IsGranted(ProjectVoter::VIEW, 'project')]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
    ): Response {
        return $this->render('weekly_menus.html.twig', [
            'project' => $project,
            'menus' => $this->getWeeklyMenus->allForProject($project->id),
        ]);
    }
}
