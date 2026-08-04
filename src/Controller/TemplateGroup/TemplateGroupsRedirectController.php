<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\TemplateGroup;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Entity\Project;

/**
 * TEMPORARY: the standalone group listing died with the module merge —
 * grouped templates live on the unified Šablony listing. This stub only
 * keeps the `template_groups` route name alive because templates/base.html.twig
 * (owned by the unified-listing task) still links it from the left nav.
 * Delete this controller together with that nav entry.
 */
final class TemplateGroupsRedirectController extends AbstractController
{
    #[Route(path: '/project/{projectId}/template-groups', name: 'template_groups')]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
    ): Response {
        return $this->redirectToRoute('templates', [
            'projectId' => $project->id,
        ], Response::HTTP_MOVED_PERMANENTLY);
    }
}
