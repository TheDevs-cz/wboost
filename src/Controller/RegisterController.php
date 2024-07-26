<?php
declare(strict_types=1);

namespace WBoost\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RegisterController extends AbstractController
{
    #[Route(path: '/registration', name: 'registration')]
    public function __invoke(): Response
    {
        throw $this->createNotFoundException();
    }
}
