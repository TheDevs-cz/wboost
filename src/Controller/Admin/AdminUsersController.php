<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\User;
use WBoost\Web\Query\GetUsersOverview;

final class AdminUsersController extends AbstractController
{
    public function __construct(
        readonly private GetUsersOverview $getUsersOverview,
    ) {
    }

    #[Route(path: '/admin/users', name: 'admin_users')]
    #[IsGranted(User::ROLE_ADMIN)]
    public function __invoke(): Response
    {
        return $this->render('admin/users.html.twig', [
            'users' => $this->getUsersOverview->all(),
        ]);
    }
}
