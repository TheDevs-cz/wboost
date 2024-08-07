<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Project;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Services\Security\ProjectVoter;

final class ProjectFontsController extends AbstractController
{
    #[Route(path: '/project/{id}/fonts', name: 'project_fonts')]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(Request $request, Manual $project): Response
    {
        return $this->render('project_fonts.html.twig', [
            'project' => $project,
        ]);
    }
}
