<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\WeeklyMenu;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\WeeklyMenu;
use WBoost\Web\Message\WeeklyMenu\DuplicateWeeklyMenu;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\WeeklyMenuVoter;

final class DuplicateWeeklyMenuController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
    ) {
    }

    #[Route(path: '/weekly-menu/{menuId}/duplicate', name: 'duplicate_weekly_menu', methods: ['POST'])]
    #[IsGranted(WeeklyMenuVoter::EDIT, 'menu')]
    public function __invoke(
        #[MapEntity(id: 'menuId')]
        WeeklyMenu $menu,
    ): Response {
        $newId = $this->provideIdentity->next();

        $this->bus->dispatch(
            new DuplicateWeeklyMenu($menu->id, $newId),
        );

        $this->addFlash('success', 'Jídelníček byl zduplikován.');

        return $this->redirectToRoute('weekly_menu_planner', [
            'menuId' => $newId,
        ]);
    }
}
