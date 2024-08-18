<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialNetwork;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Query\GetSocialNetworks;
use WBoost\Web\Services\Security\ProjectVoter;

final class SocialNetworkTemplatesController extends AbstractController
{
    public function __construct(
        readonly private GetSocialNetworks $getSocialNetworks,
    ) {
    }

    #[Route(path: '/project/{projectId}/social-networks', name: 'social_network_templates')]
    #[IsGranted(ProjectVoter::VIEW, 'project')]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
    ): Response {
        return $this->render('social_network_templates.html.twig', [
            'project' => $project,
            'templates' => $this->getSocialNetworks->allForProject($project->id),
        ]);
    }
}
