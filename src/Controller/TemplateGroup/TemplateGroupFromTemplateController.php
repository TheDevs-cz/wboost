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
use WBoost\Web\Query\GetCustomTemplates;
use WBoost\Web\Query\GetSocialNetworkTemplates;
use WBoost\Web\Services\Security\ProjectVoter;

/**
 * "Create from existing" picker: lists every template of the project (both
 * modules) with its variants as selectable design sources. Picking a variant
 * opens the group wizard prefilled with that design.
 */
final class TemplateGroupFromTemplateController extends AbstractController
{
    public function __construct(
        readonly private GetSocialNetworkTemplates $getSocialNetworkTemplates,
        readonly private GetCustomTemplates $getCustomTemplates,
    ) {
    }

    #[Route(path: '/project/{projectId}/template-groups/from-template', name: 'template_group_from_template')]
    #[IsGranted(User::ROLE_DESIGNER)]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
    ): Response {
        $socialTemplates = array_values(array_filter(
            $this->getSocialNetworkTemplates->allForProject($project->id),
            static fn ($template): bool => $template->variants() !== [],
        ));

        $customTemplates = array_values(array_filter(
            $this->getCustomTemplates->allForProject($project->id),
            static fn ($template): bool => $template->variants() !== [],
        ));

        return $this->render('template_group_from_template.html.twig', [
            'project' => $project,
            'social_templates' => $socialTemplates,
            'custom_templates' => $customTemplates,
        ]);
    }
}
