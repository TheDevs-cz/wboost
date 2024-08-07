<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Query\GetManuals;
use WBoost\Web\Services\Security\ProjectVoter;

final class ManualsController extends AbstractController
{
    public function __construct(
        readonly private GetManuals $getManuals,
    ) {
    }

    #[Route(path: '/project/{id}/manuals', name: 'manuals_list')]
    #[IsGranted(ProjectVoter::VIEW, 'project')]
    public function __invoke(Project $project): Response
    {
        return $this->render('manuals.html.twig', [
            'project' => $project,
            'manuals' => $this->getManuals->allForProject($project->id),
        ]);
    }
}
