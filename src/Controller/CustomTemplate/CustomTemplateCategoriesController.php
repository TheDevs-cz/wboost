<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\CustomTemplate;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Query\GetCustomTemplateCategories;
use WBoost\Web\Services\Security\ProjectVoter;

final class CustomTemplateCategoriesController extends AbstractController
{
    public function __construct(
        readonly private GetCustomTemplateCategories $getCustomTemplateCategories,
    ) {
    }

    #[Route(path: '/project/{projectId}/custom-template-categories', name: 'custom_template_categories')]
    #[IsGranted(ProjectVoter::VIEW, 'project')]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
    ): Response {
        return $this->render('custom_template_categories.html.twig', [
            'project' => $project,
            'categories' => $this->getCustomTemplateCategories->allForProject($project->id),
        ]);
    }
}
