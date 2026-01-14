<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Diet;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Query\GetDiets;
use WBoost\Web\Services\Security\ProjectVoter;

final class DietsController extends AbstractController
{
    public function __construct(
        readonly private GetDiets $getDiets,
    ) {
    }

    #[Route(path: '/project/{projectId}/diets', name: 'diets')]
    #[IsGranted(ProjectVoter::VIEW, 'project')]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
    ): Response {
        return $this->render('diets.html.twig', [
            'project' => $project,
            'diets' => $this->getDiets->allForProject($project->id),
        ]);
    }
}
