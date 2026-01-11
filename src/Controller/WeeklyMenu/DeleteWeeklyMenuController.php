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
use WBoost\Web\Message\WeeklyMenu\DeleteWeeklyMenu;
use WBoost\Web\Services\Security\WeeklyMenuVoter;

final class DeleteWeeklyMenuController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/delete-weekly-menu/{menuId}', name: 'delete_weekly_menu', methods: ['POST'])]
    #[IsGranted(WeeklyMenuVoter::EDIT, 'menu')]
    public function __invoke(
        #[MapEntity(id: 'menuId')]
        WeeklyMenu $menu,
    ): Response {
        $projectId = $menu->project->id;

        $this->bus->dispatch(
            new DeleteWeeklyMenu($menu->id),
        );

        $this->addFlash('success', 'Jídelníček byl smazan.');

        return $this->redirectToRoute('weekly_menus', [
            'projectId' => $projectId,
        ]);
    }
}
