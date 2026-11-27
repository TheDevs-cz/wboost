<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Project;

use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Repository\SocialPostRepository;
use WBoost\Web\Services\FacebookPage\FacebookPagePublishing;
use WBoost\Web\Services\FacebookPage\ProjectFacebookPageConnection;
use WBoost\Web\Services\Instagram\InstagramPublishing;
use WBoost\Web\Services\Instagram\ProjectInstagramConnection;
use WBoost\Web\Services\Security\ProjectVoter;

/**
 * "Facebook a Instagram" — the project's social connections: the Instagram
 * account the project publishes to (connect / reconnect / disconnect for
 * project editors, status for everyone) and the Facebook Page it publishes
 * to (same states). Users outside a network's rollout (InstagramPublishing /
 * FacebookPagePublishing) see its coming-soon copy only. Below them: every
 * post published from the project ("Publikované příspěvky", `social_post`).
 */
final class ProjectSocialController extends AbstractController
{
    public function __construct(
        readonly private InstagramPublishing $publishing,
        readonly private ProjectInstagramConnection $instagram,
        readonly private FacebookPagePublishing $facebookPublishing,
        readonly private ProjectFacebookPageConnection $facebook,
        readonly private ClockInterface $clock,
        readonly private SocialPostRepository $posts,
    ) {
    }

    #[Route(path: '/project/{projectId}/social', name: 'project_social', methods: ['GET'])]
    #[IsGranted(ProjectVoter::VIEW, 'project')]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
        Request $request,
    ): Response {
        $available = $this->publishing->isAvailable();
        $account = $available ? $this->instagram->find($project) : null;
        $now = $this->clock->now();
        $facebookAvailable = $this->facebookPublishing->isAvailable();
        $page = $facebookAvailable ? $this->facebook->find($project) : null;

        // "Publikované příspěvky": the newest 50, `?vse=1` shows (up to 1000) more.
        $limit = $request->query->getBoolean('vse') ? 1000 : 50;
        $posts = $this->posts->forProject($project, $limit + 1);

        return $this->render('project_social.html.twig', [
            'posts' => array_slice($posts, 0, $limit),
            'morePosts' => count($posts) > $limit && $limit === 50,
            'project' => $project,
            'instagramAvailable' => $available,
            'instagram' => $account,
            'instagramUsable' => $account !== null && $account->isUsable($now),
            'facebookAvailable' => $facebookAvailable,
            'facebookPage' => $page,
            'facebookUsable' => $page !== null && $page->isUsable($now),
        ]);
    }
}
