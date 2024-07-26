<?php
declare(strict_types=1);

namespace WBoost\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ForgottenPasswordController extends AbstractController
{
    #[Route(path: '/forgotten-password', name: 'forgotten_password')]
    public function __invoke(): Response
    {
        throw $this->createNotFoundException();
    }
}
