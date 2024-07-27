<?php
declare(strict_types=1);

namespace WBoost\Web\Controller\User;

use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WBoost\Web\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class UserProfileController extends AbstractController
{
    #[Route(path: '/user-profile', name: 'user_profile')]
    public function __invoke(#[CurrentUser] User $user): Response
    {
        return $this->render('user_profile.html.twig');
    }
}
