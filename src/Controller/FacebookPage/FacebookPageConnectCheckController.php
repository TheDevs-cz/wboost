<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\FacebookPage;

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
use WBoost\Web\Message\FacebookPage\ConnectProjectFacebookPage;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\FacebookPage\FacebookPageApiInterface;
use WBoost\Web\Services\FacebookPage\FacebookPagePublishing;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Services\Security\TokenCrypto;
use WBoost\Web\Value\FacebookPage;

/**
 * Return leg of "Propojit stránku": single-use state, `project_edit` on the
 * project it names, code → system-user token → the shared Pages (each with
 * its Page token): one → connected, several → the editor picks
 * (FacebookPageConnectPickController), none → error.
 */
final class FacebookPageConnectCheckController extends AbstractController
{
    public function __construct(
        readonly private FacebookPageApiInterface $api,
        readonly private FacebookPagePublishing $publishing,
        readonly private ProjectRepository $projectRepository,
        readonly private TokenCrypto $tokenCrypto,
        readonly private MessageBusInterface $bus,
        readonly private LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/oauth/facebook-page/check', name: 'oauth_facebook_page_check', methods: ['GET'])]
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        $session = $request->getSession();
        $pending = $session->get(FacebookPageConnectStartController::SESSION_KEY);
        $session->remove(FacebookPageConnectStartController::SESSION_KEY);

        $state = $request->query->getString('state');

        if (
            !is_array($pending)
            || !is_string($pending['state'] ?? null)
            || !is_string($pending['projectId'] ?? null)
            || !Uuid::isValid($pending['projectId'])
            || $state === ''
            || !hash_equals($pending['state'], $state)
        ) {
            $this->addFlash('danger', 'Propojení s Facebookem vypršelo nebo bylo neplatné. Zkuste to prosím znovu.');

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
            $this->addFlash('info', 'Propojení s Facebookem bylo zrušeno.');

            return $back;
        }

        try {
            $token = $this->api->exchangeCode(
                $code,
                $this->generateUrl('oauth_facebook_page_check', [], UrlGeneratorInterface::ABSOLUTE_URL),
            );
            $pages = $this->api->pages($token->accessToken);
        } catch (MetaApiError $exception) {
            $this->logger->warning('Facebook Page connect failed.', ['exception' => $exception]);
            $this->addFlash('danger', 'Propojení s Facebookem se nezdařilo. Zkuste to prosím znovu.');

            return $back;
        }

        if ($pages === []) {
            $this->addFlash('danger', 'Meta nesdílela žádnou facebookovou stránku, na kterou lze publikovat. Propojte to znovu a v okně Mety zaškrtněte stránku projektu.');

            return $back;
        }

        $ownerId = $this->tokenOwnerId($token->accessToken);

        if (count($pages) > 1) {
            $session->set(FacebookPageConnectPickController::SESSION_KEY, [
                'projectId' => $project->id->toString(),
                'expiresAt' => $token->expiresAtTimestamp,
                'ownerId' => $ownerId,
                'pages' => array_map(
                    fn (FacebookPage $page): array => ['id' => $page->id, 'name' => $page->name, 'token' => $this->tokenCrypto->encrypt($page->accessToken)],
                    $pages,
                ),
            ]);

            return $this->redirectToRoute('project_facebook_pick', ['projectId' => $project->id]);
        }

        $page = $pages[0];
        $this->bus->dispatch(new ConnectProjectFacebookPage(
            $project->id->toString(),
            $user->id->toString(),
            $page->id,
            $ownerId,
            $page->name,
            $page->accessToken,
            $token->expiresAtTimestamp,
        ));

        $this->addFlash('success', sprintf('Facebooková stránka %s je propojena s projektem.', $page->name));

        return $back;
    }

    private function tokenOwnerId(string $accessToken): null|string
    {
        try {
            return $this->api->tokenOwnerId($accessToken);
        } catch (MetaApiError $exception) {
            $this->logger->warning('Facebook token owner lookup failed.', ['exception' => $exception]);

            return null;
        }
    }
}
