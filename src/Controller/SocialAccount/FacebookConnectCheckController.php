<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialAccount;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Exception\InvalidStateException;
use KnpU\OAuth2ClientBundle\Exception\MissingAuthorizationCodeException;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\FacebookUser;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\User;
use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Exceptions\SocialAccountAlreadyLinked;
use WBoost\Web\Message\SocialAccount\ConnectFacebookAccount;
use WBoost\Web\Services\Meta\MetaGraphApiInterface;

/**
 * Return leg of the profile-page "connect Facebook" flow: swap the code for
 * a token, exchange it for the ~60-day long-lived one, and link the Facebook
 * identity (with granted scopes) to the CURRENTLY logged-in user.
 */
final class FacebookConnectCheckController extends AbstractController
{
    public function __construct(
        readonly private ClientRegistry $clientRegistry,
        readonly private MetaGraphApiInterface $metaGraphApi,
        readonly private MessageBusInterface $bus,
        readonly private LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/oauth/facebook/connect/check', name: 'oauth_facebook_connect_check')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        if (!$request->query->has('code')) {
            $this->addFlash('info', 'Propojení s Facebookem bylo zrušeno.');

            return $this->redirectToRoute('user_profile');
        }

        $client = $this->clientRegistry->getClient('facebook_connect');

        try {
            $accessToken = $client->getAccessToken();

            $facebookUser = $client->fetchUserFromToken($accessToken);
            assert($facebookUser instanceof FacebookUser);

            $longLived = $this->metaGraphApi->exchangeLongLivedUserToken($accessToken->getToken());
            $scopes = $this->metaGraphApi->fetchGrantedScopes($longLived->accessToken);

            $this->bus->dispatch(new ConnectFacebookAccount(
                $user->id->toString(),
                (string) $facebookUser->getId(),
                $longLived->accessToken,
                $longLived->expiresAtTimestamp,
                $scopes,
                $facebookUser->getName(),
            ));
        } catch (HandlerFailedException $exception) {
            if ($exception->getPrevious() instanceof SocialAccountAlreadyLinked) {
                $this->addFlash('danger', 'Tento facebookový účet je již propojen s jiným uživatelem.');

                return $this->redirectToRoute('user_profile');
            }

            throw $exception->getPrevious() ?? $exception;
        } catch (MetaApiError | IdentityProviderException | InvalidStateException | MissingAuthorizationCodeException $exception) {
            $this->logger->warning('Facebook connect failed.', ['exception' => $exception]);
            $this->addFlash('danger', 'Propojení s Facebookem se nezdařilo. Zkuste to prosím znovu.');

            return $this->redirectToRoute('user_profile');
        }

        if (!in_array('pages_show_list', $scopes, true) || !in_array('pages_manage_posts', $scopes, true)) {
            $this->addFlash('info', 'Facebook je propojen, ale nepovolili jste všechna oprávnění — publikování na stránky nemusí fungovat. Propojte účet znovu a povolte vše.');
        } else {
            $this->addFlash('success', 'Facebookový účet byl propojen.');
        }

        return $this->redirectToRoute('user_profile');
    }
}
