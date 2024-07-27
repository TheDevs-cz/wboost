<?php
declare(strict_types=1);

namespace WBoost\Web\Controller\Authentication;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ResetPasswordController extends AbstractController
{
    #[Route(path: '/reset-password', name: 'reset_password')]
    public function __invoke(): Response
    {
        throw $this->createNotFoundException();
    }
}
