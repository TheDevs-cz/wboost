<?php
declare(strict_types=1);

namespace WBoost\Web\Controller;

use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WBoost\Web\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The application's entry point behind the login: a stable target for
 * form_login, a sane bookmark, and the natural home for a real dashboard
 * later. `/` belongs to the static marketing site (see landing/README.md),
 * so the app deliberately owns no route there.
 */
final class DashboardController extends AbstractController
{
    #[Route(path: '/dashboard', name: 'dashboard')]
    public function __invoke(#[CurrentUser] User $user): Response
    {
        return $this->redirectToRoute('projects');
    }
}
