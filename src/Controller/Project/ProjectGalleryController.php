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
 * Standalone management page for a project's shared image gallery — the same
 * Project:ImageGallery Live Component used inside the canvas editor modal,
 * rendered full-page (`modal: false`) so admins can organize folders and
 * images without opening a template. The gallery is project-wide: the social
 * network and custom-template editors both feed off it. Linked from the left navigation
 * and from both module pages.
 */
final class ProjectGalleryController extends AbstractController
{
    #[Route(path: '/project/{projectId}/gallery', name: 'project_gallery')]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
    ): Response {
        return $this->render('project_gallery.html.twig', [
            'project' => $project,
        ]);
    }
}
