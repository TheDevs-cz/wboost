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
use WBoost\Web\Services\Security\TokenCrypto;
use WBoost\Web\Value\InstagramProfile;

/**
 * Return leg of "Propojit Instagram" — the ONE redirect URL saved in the
 * Unified Login configuration (`/oauth/instagram/check`). Checks the
 * single-use state, re-checks `project_edit` on the project it names, swaps
 * the code for the (Business Integration System User) token and reads the
 * Instagram accounts the client shared: one → connected right away, several
 * → the editor picks one (InstagramConnectPickController), none → error.
 */
final class InstagramConnectCheckController extends AbstractController
{
    public function __construct(
        readonly private InstagramApiInterface $instagramApi,
        readonly private InstagramPublishing $publishing,
        readonly private ProjectRepository $projectRepository,
        readonly private TokenCrypto $tokenCrypto,
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
            // error=access_denied (the person cancelled the login).
            $this->addFlash('info', 'Propojení s Instagramem bylo zrušeno.');

            return $back;
        }

        try {
            $token = $this->instagramApi->exchangeCode(
                $code,
                $this->generateUrl('oauth_instagram_check', [], UrlGeneratorInterface::ABSOLUTE_URL),
            );
            $accounts = $this->instagramApi->assignedAccounts($token->accessToken);
        } catch (MetaApiError $exception) {
            $this->logger->warning('Instagram connect failed.', ['exception' => $exception]);
            $this->addFlash('danger', 'Propojení s Instagramem se nezdařilo. Zkuste to prosím znovu.');

            return $back;
        }

        if ($accounts === []) {
            $this->addFlash('danger', 'Meta nesdílela žádný instagramový účet. Propojte to znovu a v okně Mety zaškrtněte instagramový účet, na který má projekt publikovat.');

            return $back;
        }

        $ownerId = $this->tokenOwnerId($token->accessToken);

        if (count($accounts) > 1) {
            $session->set(InstagramConnectPickController::SESSION_KEY, [
                'projectId' => $project->id->toString(),
                'token' => $this->tokenCrypto->encrypt($token->accessToken),
                'expiresAt' => $token->expiresAtTimestamp,
                'ownerId' => $ownerId,
                'accounts' => array_map(
                    static fn (InstagramProfile $account): array => ['id' => $account->userId, 'username' => $account->username, 'name' => $account->name],
                    $accounts,
                ),
            ]);

            return $this->redirectToRoute('project_instagram_pick', ['projectId' => $project->id]);
        }

        $account = $accounts[0];
        $this->bus->dispatch(new ConnectProjectInstagram(
            $project->id->toString(),
            $user->id->toString(),
            $account->userId,
            $ownerId,
            $account->username,
            $account->name,
            null,
            $token->accessToken,
            $token->expiresAtTimestamp,
            [],
        ));

        $this->addFlash('success', sprintf('Instagram @%s je propojen s projektem.', $account->username));

        return $back;
    }

    /**
     * Informational only (matched by Meta's callbacks) — a failure here must
     * not block connecting.
     */
    private function tokenOwnerId(string $accessToken): null|string
    {
        try {
            return $this->instagramApi->tokenOwnerId($accessToken);
        } catch (MetaApiError $exception) {
            $this->logger->warning('Instagram token owner lookup failed.', ['exception' => $exception]);

            return null;
        }
    }
}
