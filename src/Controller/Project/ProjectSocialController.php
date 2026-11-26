<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Project;

use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Services\Instagram\InstagramPublishing;
use WBoost\Web\Services\Instagram\ProjectInstagramConnection;
use WBoost\Web\Services\Security\ProjectVoter;

/**
 * "Facebook a Instagram" — the project's social connections: the Instagram
 * account the project publishes to (connect / reconnect / disconnect for
 * project editors, status for everyone) and the Facebook slot that is still
 * "připravujeme". Users outside the rollout (InstagramPublishing) see the
 * coming-soon copy only.
 */
final class ProjectSocialController extends AbstractController
{
    public function __construct(
        readonly private InstagramPublishing $publishing,
        readonly private ProjectInstagramConnection $instagram,
        readonly private ClockInterface $clock,
    ) {
    }

    #[Route(path: '/project/{projectId}/social', name: 'project_social', methods: ['GET'])]
    #[IsGranted(ProjectVoter::VIEW, 'project')]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
    ): Response {
        $available = $this->publishing->isAvailable();
        $account = $available ? $this->instagram->find($project) : null;
        $now = $this->clock->now();

        return $this->render('project_social.html.twig', [
            'project' => $project,
            'instagramAvailable' => $available,
            'instagram' => $account,
            'instagramUsable' => $account !== null && $account->isUsable($now),
        ]);
    }
}
