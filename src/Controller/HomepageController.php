<?php
declare(strict_types=1);

namespace BrandManuals\Web\Controller;

use BrandManuals\Web\Repository\ProjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomepageController extends AbstractController
{
    public function __construct(
        readonly private ProjectRepository $projectRepository,
    ) {
    }

    #[Route(path: '/', name: 'homepage', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('homepage.html.twig', [
            'projects' => $this->projectRepository->all(),
        ]);
    }
}
