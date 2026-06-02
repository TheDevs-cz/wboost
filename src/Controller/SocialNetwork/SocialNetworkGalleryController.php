<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialNetwork;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Services\Security\ProjectVoter;

/**
 * Standalone management page for a project's reusable image gallery — the same
 * Project:ImageGallery Live Component used inside the editor modal, rendered
 * full-page (`modal: false`) so admins can organize folders and images without
 * opening a template. Reachable from the social-networks page next to
 * "Kategorie".
 */
final class SocialNetworkGalleryController extends AbstractController
{
    #[Route(path: '/project/{projectId}/social-network-gallery', name: 'social_network_gallery')]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
    ): Response {
        return $this->render('social_network_gallery.html.twig', [
            'project' => $project,
        ]);
    }
}
