<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Instagram;

use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WBoost\Web\Entity\User;
use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Message\Instagram\ConnectProjectInstagram;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\Instagram\InstagramApiInterface;
use WBoost\Web\Services\Instagram\InstagramPublishing;
use WBoost\Web\Services\Security\ProjectVoter;

/**
 * Return leg of "Propojit Instagram" — the ONE redirect URI registered in the
 * Meta app (`/oauth/instagram/check`). Checks the single-use state, re-checks
 * `project_edit` on the project it names, swaps the code for a short-lived
 * token, then a ~60-day one, reads the profile and stores the connection.
 */
final class InstagramConnectCheckController extends AbstractController
{
    public function __construct(
        readonly private InstagramApiInterface $instagramApi,
        readonly private InstagramPublishing $publishing,
        readonly private ProjectRepository $projectRepository,
        readonly private MessageBusInterface $bus,
        readonly private LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/oauth/instagram/check', name: 'oauth_instagram_check', methods: ['GET'])]
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        $session = $request->getSession();
        $pending = $session->get(InstagramConnectStartController::SESSION_KEY);
        $session->remove(InstagramConnectStartController::SESSION_KEY);

        $state = $request->query->getString('state');

        if (
            !is_array($pending)
            || !is_string($pending['state'] ?? null)
            || !is_string($pending['projectId'] ?? null)
            || !Uuid::isValid($pending['projectId'])
            || $state === ''
            || !hash_equals($pending['state'], $state)
        ) {
            $this->addFlash('danger', 'Propojení s Instagramem vypršelo nebo bylo neplatné. Zkuste to prosím znovu.');

            return $this->redirectToRoute('dashboard');
        }

        $project = $this->projectRepository->get(Uuid::fromString($pending['projectId']));
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $back = $this->redirectToRoute('project_social', ['projectId' => $project->id]);

        if (!$this->publishing->isAvailable()) {
            return $back;
        }

        $code = $request->query->getString('code');

        if ($code === '') {
            // error=access_denied (the person pressed "Not now" / cancel).
            $this->addFlash('info', 'Propojení s Instagramem bylo zrušeno.');

            return $back;
        }

        try {
            $shortLived = $this->instagramApi->exchangeCode(
                $code,
                $this->generateUrl('oauth_instagram_check', [], UrlGeneratorInterface::ABSOLUTE_URL),
            );

            if (!in_array('instagram_business_content_publish', $shortLived->permissions, true)) {
                $this->addFlash('danger', 'Instagram nepovolil publikování příspěvků. Propojte účet znovu a v okně Instagramu povolte všechna oprávnění.');

                return $back;
            }

            $longLived = $this->instagramApi->exchangeLongLivedToken($shortLived->accessToken);
            $profile = $this->instagramApi->fetchProfile($longLived->accessToken);
        } catch (MetaApiError $exception) {
            $this->logger->warning('Instagram connect failed.', ['exception' => $exception]);
            $this->addFlash('danger', 'Propojení s Instagramem se nezdařilo. Zkuste to prosím znovu.');

            return $back;
        }

        $this->bus->dispatch(new ConnectProjectInstagram(
            $project->id->toString(),
            $user->id->toString(),
            $profile->userId,
            $profile->appScopedId ?? $shortLived->userId,
            $profile->username,
            $profile->name,
            $profile->accountType,
            $longLived->accessToken,
            $longLived->expiresAtTimestamp,
            $shortLived->permissions,
        ));

        $this->addFlash('success', sprintf('Instagram @%s je propojen s projektem.', $profile->username));

        return $back;
    }
}
