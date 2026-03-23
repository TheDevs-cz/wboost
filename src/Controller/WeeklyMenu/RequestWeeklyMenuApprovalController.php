<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\WeeklyMenu;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\WeeklyMenu;
use WBoost\Web\Message\WeeklyMenu\RequestWeeklyMenuApproval;
use WBoost\Web\Services\Security\WeeklyMenuVoter;

final class RequestWeeklyMenuApprovalController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/weekly-menu/{menuId}/request-approval', name: 'request_weekly_menu_approval', methods: ['POST'])]
    #[IsGranted(WeeklyMenuVoter::EDIT, 'menu')]
    public function __invoke(
        #[MapEntity(id: 'menuId')]
        WeeklyMenu $menu,
        #[CurrentUser]
        UserInterface $user,
    ): Response {
        $this->bus->dispatch(
            new RequestWeeklyMenuApproval(
                $menu->id,
                $user->getUserIdentifier(),
            ),
        );

        $this->addFlash('success', 'Žádost o schválení byla odeslána na ' . $menu->approvalEmail . '.');

        return $this->redirectToRoute('weekly_menus', [
            'projectId' => $menu->project->id,
        ]);
    }
}
