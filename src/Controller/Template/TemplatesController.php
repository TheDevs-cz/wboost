<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Template;

use WBoost\Web\Attribute\RequiresProjectModule;
use WBoost\Web\Value\ProjectModule;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Query\GetExportVersions;
use WBoost\Web\Query\GetTemplateCategories;
use WBoost\Web\Query\GetTemplateListing;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Services\TemplateDocument\TemplateDocumentsFeature;

#[RequiresProjectModule(ProjectModule::Templates)]
final class TemplatesController extends AbstractController
{
    public function __construct(
        readonly private GetTemplateCategories $getTemplateCategories,
        readonly private GetExportVersions $getExportVersions,
        readonly private GetTemplateListing $getTemplateListing,
        readonly private TemplateDocumentsFeature $templateDocumentsFeature,
    ) {
    }

    #[Route(path: '/project/{projectId}/templates', name: 'templates')]
    #[IsGranted(ProjectVoter::VIEW, 'project')]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
    ): Response {
        $listing = $this->getTemplateListing->forProject(
            $project->id,
            array_values($this->getTemplateCategories->allForProject($project->id)),
            $this->templateDocumentsFeature->isEnabled(),
        );

        return $this->render('templates.html.twig', [
            'project' => $project,
            // Cards per category — templates and multi-page documents share
            // the sort space; templates attached as document pages are left
            // out (the document card stands for them).
            'categories' => array_values(array_filter($listing['categories'], static fn (array $entry): bool => $entry['items'] !== [])),
            'uncategorized' => $listing['uncategorized'],
            // templateId → latest export version summary, for the cards'
            // "naposledy exportováno" line.
            'last_exports' => $this->getExportVersions->latestForProjectTemplates($project->id),
        ]);
    }
}
