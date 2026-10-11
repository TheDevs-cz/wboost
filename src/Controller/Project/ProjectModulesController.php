<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Project;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Services\Security\ProjectVoter;

/**
 * "Objevte další moduly": the project's switched-off modules, each with a
 * one-click "Zapnout modul" for project editors (read-only members are told
 * an editor can do it). The upsell path that replaces listing every module
 * in the navigation.
 */
final class ProjectModulesController extends AbstractController
{
    #[Route(path: '/project/{projectId}/modules', name: 'project_modules', methods: ['GET'])]
    #[IsGranted(ProjectVoter::VIEW, 'project')]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
    ): Response {
        return $this->render('project_modules.html.twig', [
            'project' => $project,
        ]);
    }
}
