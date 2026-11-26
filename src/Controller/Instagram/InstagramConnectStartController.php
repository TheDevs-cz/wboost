<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Instagram;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Services\Instagram\InstagramApiInterface;
use WBoost\Web\Services\Instagram\InstagramPublishing;
use WBoost\Web\Services\Security\ProjectVoter;

/**
 * "Propojit Instagram" on the project's Facebook a Instagram page: sends the
 * project editor to Instagram's consent screen (Business Login for
 * Instagram). The project travels in a session-bound, single-use `state` —
 * the redirect URI itself is ONE fixed URL (it must match the app settings
 * exactly), so it cannot carry the project.
 */
final class InstagramConnectStartController extends AbstractController
{
    public const string SESSION_KEY = 'instagram_connect_state';

    public const array SCOPES = [
        'instagram_business_basic',
        'instagram_business_content_publish',
    ];

    public function __construct(
        readonly private InstagramApiInterface $instagramApi,
        readonly private InstagramPublishing $publishing,
    ) {
    }

    #[Route(path: '/project/{projectId}/instagram/connect', name: 'project_instagram_connect', methods: ['GET'])]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
        Request $request,
    ): Response {
        if (!$this->publishing->isAvailable()) {
            throw $this->createNotFoundException();
        }

        $state = bin2hex(random_bytes(16));
        $request->getSession()->set(self::SESSION_KEY, [
            'state' => $state,
            'projectId' => $project->id->toString(),
        ]);

        return $this->redirect($this->instagramApi->authorizationUrl(
            $this->generateUrl('oauth_instagram_check', [], UrlGeneratorInterface::ABSOLUTE_URL),
            $state,
            self::SCOPES,
        ));
    }
}
