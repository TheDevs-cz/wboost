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
use WBoost\Web\Entity\TemplateCategory;
use WBoost\Web\Query\GetTemplateCategories;
use WBoost\Web\Query\GetTemplateExportCounts;
use WBoost\Web\Query\GetTemplateListing;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Value\TemplateListingItem;

#[RequiresProjectModule(ProjectModule::Templates)]
final class TemplatesController extends AbstractController
{
    public function __construct(
        readonly private GetTemplateCategories $getTemplateCategories,
        readonly private GetTemplateListing $getTemplateListing,
        readonly private GetTemplateExportCounts $getTemplateExportCounts,
    ) {
    }

    #[Route(path: '/project/{projectId}/templates', name: 'templates')]
    #[IsGranted(ProjectVoter::VIEW, 'project')]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
    ): Response {
        $allCategories = array_values($this->getTemplateCategories->allForProject($project->id));
        $listing = $this->getTemplateListing->forProject($project->id, $allCategories);

        $items = $listing['uncategorized'];
        foreach ($listing['categories'] as $entry) {
            $items = [...$items, ...$entry['items']];
        }

        return $this->render('templates.html.twig', [
            'project' => $project,
            // Cards per category — templates and multi-page documents share
            // the sort space; templates attached as document pages are left
            // out (the document card stands for them).
            'categories' => array_values(array_filter($listing['categories'], static fn (array $entry): bool => $entry['items'] !== [])),
            'uncategorized' => $listing['uncategorized'],
            // Every category id in position order, empty ones included — the
            // header drag-reorder re-slots the visible (non-empty) ones into
            // this list, so hidden empty categories keep their place when
            // the full order goes to sort_template_categories.
            'category_order' => array_map(static fn (TemplateCategory $category): string => $category->id->toString(), $allCategories),
            'top' => $this->mostUsed($project, $items),
        ]);
    }

    /**
     * "Nejpoužívanější": the (at most) four cards with the most exports in the
     * last 30 days. A document counts the exports of all its pages. Fewer
     * than two used cards make no meaningful row → empty.
     *
     * @param list<TemplateListingItem> $items
     * @return list<array{item: TemplateListingItem, uses: int}>
     */
    private function mostUsed(Project $project, array $items): array
    {
        $counts = $this->getTemplateExportCounts->forProjectSince($project->id, new \DateTimeImmutable('-30 days'));

        if ($counts === []) {
            return [];
        }

        $ranked = [];
        foreach ($items as $item) {
            $uses = 0;

            if ($item->template !== null) {
                $uses = $counts[$item->template->id->toString()] ?? 0;
            } elseif ($item->document !== null) {
                foreach ($item->document->pages() as $page) {
                    $uses += $counts[$page->template->id->toString()] ?? 0;
                }
            }

            if ($uses > 0) {
                $ranked[] = ['item' => $item, 'uses' => $uses];
            }
        }

        usort($ranked, static fn (array $a, array $b): int => $b['uses'] <=> $a['uses']);
        $ranked = array_slice($ranked, 0, 4);

        return count($ranked) >= 2 ? $ranked : [];
    }
}
