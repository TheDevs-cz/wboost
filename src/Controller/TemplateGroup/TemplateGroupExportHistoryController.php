<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\TemplateGroup;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateGroup;
use WBoost\Web\MessageHandler\Template\RecordTemplateExportVersionHandler;
use WBoost\Web\Query\GetExportVersions;
use WBoost\Web\Query\GetTemplateGroupMembers;
use WBoost\Web\Services\Security\TemplateGroupVoter;
use WBoost\Web\Services\Template\SummarizeExportVersion;

/**
 * The full export history of a synchronized template's group fill page —
 * the group twin of {@see \WBoost\Web\Controller\Template\TemplateVariantExportHistoryController}.
 */
final class TemplateGroupExportHistoryController extends AbstractController
{
    public function __construct(
        readonly private GetExportVersions $getExportVersions,
        readonly private GetTemplateGroupMembers $members,
        readonly private SummarizeExportVersion $summarize,
    ) {
    }

    #[Route(path: '/template-group/{groupId}/export-history', name: 'template_group_export_history', methods: ['GET'])]
    #[IsGranted(TemplateGroupVoter::VIEW, 'group')]
    public function __invoke(
        #[MapEntity(id: 'groupId')]
        TemplateGroup $group,
    ): Response {
        $history = $this->getExportVersions->forGroup($group->id);

        return $this->render('template_export_history.html.twig', [
            'project' => $group->project,
            'template' => $this->members->template($group->id),
            'variant' => null,
            'group' => $group,
            'subject_label' => $group->name,
            'history' => $history,
            'history_cap' => RecordTemplateExportVersionHandler::MAX_VERSIONS,
            // Labels unify over every member dimension, first-wins by inputId
            // — the same join the group fill form does.
            'summaries' => $this->summarize->forVersions($history->all(), $this->members->variants($group->id)),
            'load_route_name' => 'template_group_fill',
            'load_route_params' => ['groupId' => $group->id->toString()],
            'fill_url' => $this->generateUrl('template_group_fill', ['groupId' => $group->id]),
        ]);
    }
}
