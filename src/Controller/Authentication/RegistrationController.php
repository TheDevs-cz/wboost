<?php
declare(strict_types=1);

namespace WBoost\Web\Controller\Authentication;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class RegistrationController extends AbstractController
{
    #[Route(path: '/registration', name: 'registration')]
    public function __invoke(#[CurrentUser] null|UserInterface $user = null): Response
    {
        if ($user !== null) {
            return $this->redirectToRoute('homepage');
        }

        return $this->render('registration.html.twig');
    }
}
