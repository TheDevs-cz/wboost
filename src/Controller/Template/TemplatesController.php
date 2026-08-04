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
use WBoost\Web\Query\GetTemplates;
use WBoost\Web\Services\Security\ProjectVoter;

final class TemplatesController extends AbstractController
{
    public function __construct(
        readonly private GetTemplates $getTemplates,
        readonly private GetTemplateCategories $getTemplateCategories,
    ) {
    }

    #[Route(path: '/project/{projectId}/templates', name: 'templates')]
    #[IsGranted(ProjectVoter::VIEW, 'project')]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
    ): Response {
        return $this->render('templates.html.twig', [
            'project' => $project,
            'categories' => $this->getTemplateCategories->allForProject($project->id),
            'templates_without_category' => $this->getTemplates->withoutCategoryForProject($project->id),
        ]);
    }
}
