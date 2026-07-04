<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Admin;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\User\DeleteUser;

final class DeleteUserController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/admin/users/{id}/delete', name: 'admin_delete_user')]
    #[IsGranted(User::ROLE_ADMIN)]
    public function __invoke(
        #[MapEntity(id: 'id')]
        User $user,
        #[CurrentUser]
        User $admin,
    ): Response {
        if ($admin->id->equals($user->id)) {
            $this->addFlash('danger', 'Nemůžete smazat vlastní účet.');

            return $this->redirectToRoute('admin_users');
        }

        $this->bus->dispatch(new DeleteUser($user->id->toString()));

        $this->addFlash('success', 'Uživatel byl smazán.');

        return $this->redirectToRoute('admin_users');
    }
}
