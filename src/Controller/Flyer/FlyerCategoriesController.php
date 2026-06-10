<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Flyer;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Query\GetFlyerCategories;
use WBoost\Web\Services\Security\ProjectVoter;

final class FlyerCategoriesController extends AbstractController
{
    public function __construct(
        readonly private GetFlyerCategories $getFlyerCategories,
    ) {
    }

    #[Route(path: '/project/{projectId}/flyer-categories', name: 'flyer_categories')]
    #[IsGranted(ProjectVoter::VIEW, 'project')]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
    ): Response {
        return $this->render('flyer_categories.html.twig', [
            'project' => $project,
            'categories' => $this->getFlyerCategories->allForProject($project->id),
        ]);
    }
}
