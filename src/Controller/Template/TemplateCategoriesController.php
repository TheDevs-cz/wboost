<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Template;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Query\GetTemplateCategories;
use WBoost\Web\Services\Security\ProjectVoter;

final class TemplateCategoriesController extends AbstractController
{
    public function __construct(
        readonly private GetTemplateCategories $getTemplateCategories,
    ) {
    }

    #[Route(path: '/project/{projectId}/template-categories', name: 'template_categories')]
    #[IsGranted(ProjectVoter::VIEW, 'project')]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
    ): Response {
        return $this->render('template_categories.html.twig', [
            'project' => $project,
            'categories' => $this->getTemplateCategories->allForProject($project->id),
        ]);
    }
}
