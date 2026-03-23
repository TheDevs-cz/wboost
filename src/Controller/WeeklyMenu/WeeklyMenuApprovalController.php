<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\WeeklyMenu;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Entity\WeeklyMenu;
use WBoost\Web\Message\WeeklyMenu\ApproveWeeklyMenu;
use WBoost\Web\Message\WeeklyMenu\DenyWeeklyMenu;
use WBoost\Web\Value\WeeklyMenuApprovalStatus;

final class WeeklyMenuApprovalController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/weekly-menu/{menuId}/approval/{hash}', name: 'weekly_menu_approval', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request,
        #[MapEntity(id: 'menuId')]
        WeeklyMenu $menu,
        string $hash,
    ): Response {
        if ($menu->approvalHash === null || !hash_equals($menu->approvalHash, $hash)) {
            throw $this->createNotFoundException();
        }

        if ($menu->approvalStatus !== WeeklyMenuApprovalStatus::Pending) {
            return $this->render('weekly_menu/approval.html.twig', [
                'menu' => $menu,
                'alreadyResponded' => true,
            ]);
        }

        if ($request->isMethod('POST')) {
            $action = $request->request->getString('action');
            $comment = $request->request->getString('comment');
            $comment = $comment !== '' ? $comment : null;

            if ($action === 'approve') {
                $this->bus->dispatch(new ApproveWeeklyMenu($menu->id, $hash, $comment));
            } elseif ($action === 'deny') {
                $this->bus->dispatch(new DenyWeeklyMenu($menu->id, $hash, $comment));
            }

            return $this->render('weekly_menu/approval.html.twig', [
                'menu' => $menu,
                'submitted' => true,
                'action' => $action,
            ]);
        }

        return $this->render('weekly_menu/approval.html.twig', [
            'menu' => $menu,
            'alreadyResponded' => false,
        ]);
    }
}
