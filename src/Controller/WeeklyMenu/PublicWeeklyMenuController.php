<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\WeeklyMenu;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Entity\WeeklyMenu;

final class PublicWeeklyMenuController extends AbstractController
{
    #[Route(path: '/weekly-menu/{menuId}/public', name: 'public_weekly_menu')]
    public function __invoke(
        #[MapEntity(id: 'menuId')]
        WeeklyMenu $menu,
    ): Response {
        return $this->render('weekly_menu/public.html.twig', [
            'menu' => $menu,
        ]);
    }
}
