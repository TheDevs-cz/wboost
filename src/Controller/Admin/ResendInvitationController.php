<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Admin;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\User\ResendInvitation;

final class ResendInvitationController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/admin/users/{id}/resend-invitation', name: 'admin_resend_invitation')]
    #[IsGranted(User::ROLE_ADMIN)]
    public function __invoke(
        #[MapEntity(id: 'id')]
        User $user,
    ): Response {
        $this->bus->dispatch(new ResendInvitation($user->id->toString()));

        $this->addFlash('success', 'Pozvánka byla znovu odeslána.');

        return $this->redirectToRoute('admin_users');
    }
}
