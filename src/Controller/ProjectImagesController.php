<?php

declare(strict_types=1);

namespace BrandManuals\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProjectImagesController extends AbstractController
{
    #[Route(path: '/project-images/{projectId}', methods: ['GET', 'POST'])]
    public function __invoke(): Response
    {
        return $this->json([
            'status' => 'ok',
            'time' => time(),
        ]);
    }
}
