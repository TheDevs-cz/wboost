<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\WeeklyMenu;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\WeeklyMenu;
use WBoost\Web\Services\Security\WeeklyMenuVoter;

final class EditWeeklyMenuController extends AbstractController
{
    #[Route(path: '/edit-weekly-menu/{menuId}', name: 'edit_weekly_menu')]
    #[IsGranted(WeeklyMenuVoter::EDIT, 'menu')]
    public function __invoke(
        #[MapEntity(id: 'menuId')]
        WeeklyMenu $menu,
    ): Response {
        return $this->render('edit_weekly_menu.html.twig', [
            'menu' => $menu,
            'project' => $menu->project,
        ]);
    }
}
