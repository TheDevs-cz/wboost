<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\TemplateGroup;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Query\GetTemplateGroups;
use WBoost\Web\Services\Security\ProjectVoter;

final class TemplateGroupsController extends AbstractController
{
    public function __construct(
        readonly private GetTemplateGroups $getTemplateGroups,
    ) {
    }

    #[Route(path: '/project/{projectId}/template-groups', name: 'template_groups')]
    #[IsGranted(User::ROLE_DESIGNER)]
    #[IsGranted(ProjectVoter::VIEW, 'project')]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
    ): Response {
        return $this->render('template_groups.html.twig', [
            'project' => $project,
            'groups' => $this->getTemplateGroups->allForProject($project->id),
        ]);
    }
}
