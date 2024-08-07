<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Project;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Services\Security\ProjectVoter;

final class ProjectManualController extends AbstractController
{
    #[Route(path: '/project/{id}/manual', name: 'project_manual')]
    #[IsGranted(ProjectVoter::VIEW, 'project')]
    public function __invoke(Manual $project): Response
    {
        return $this->render('project_manual.html.twig', [
            'project' => $project,
        ]);
    }
}
