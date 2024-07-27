<?php
declare(strict_types=1);

namespace WBoost\Web\Controller\Authentication;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use WBoost\Web\Entity\User;

final class LoginController extends AbstractController
{
    public function __construct(
        readonly private AuthenticationUtils $authenticationUtils,
    ) {
    }

    #[Route(path: '/login', name: 'login')]
    public function __invoke(#[CurrentUser] null|User $user = null): Response
    {
        if ($user !== null) {
            return $this->redirectToRoute('homepage');
        }

        $error = $this->authenticationUtils->getLastAuthenticationError();
        $lastUsername = $this->authenticationUtils->getLastUsername();

        return $this->render('login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }
}
