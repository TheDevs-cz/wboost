<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\FacebookPage;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Services\FacebookPage\FacebookPageApiInterface;
use WBoost\Web\Services\FacebookPage\FacebookPagePublishing;
use WBoost\Web\Services\Security\ProjectVoter;

/**
 * "Propojit stránku" on the project's Facebook a Instagram page: Facebook
 * Login for Business with the Pages configuration. The project travels in a
 * session-bound single-use `state`; the redirect URI is one fixed URL
 * (`/oauth/facebook-page/check`, registered in Facebook Login for Business →
 * Settings).
 */
final class FacebookPageConnectStartController extends AbstractController
{
    public const string SESSION_KEY = 'facebook_page_connect_state';

    public function __construct(
        readonly private FacebookPageApiInterface $api,
        readonly private FacebookPagePublishing $publishing,
    ) {
    }

    #[Route(path: '/project/{projectId}/facebook/connect', name: 'project_facebook_connect', methods: ['GET'])]
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

        return $this->redirect($this->api->authorizationUrl(
            $this->generateUrl('oauth_facebook_page_check', [], UrlGeneratorInterface::ABSOLUTE_URL),
            $state,
        ));
    }
}
