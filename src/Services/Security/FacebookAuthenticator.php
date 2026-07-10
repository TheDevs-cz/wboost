<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Security;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\FacebookUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use WBoost\Web\Entity\User;
use WBoost\Web\Exceptions\AccessAlreadyRequested;
use WBoost\Web\Exceptions\EmailAlreadyRegistered;
use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Message\SocialAccount\ConnectFacebookAccount;
use WBoost\Web\Message\User\RequestAccess;
use WBoost\Web\Repository\SocialAccountRepository;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Services\Meta\MetaGraphApiInterface;
use WBoost\Web\Value\SocialProvider;

/**
 * "Sign in with Facebook" (route oauth_facebook_check). Three outcomes:
 *
 *  1. Facebook identity already linked → log that user in.
 *  2. Facebook e-mail matches an existing user → auto-link + log in (safe:
 *     Facebook only hands out provider-verified e-mails).
 *  3. Unknown e-mail → file a RegistrationRequest (the app is invite-gated;
 *     social sign-up must NOT bypass admin approval) and explain via flash.
 *
 * The firewall's UserChecker still runs, so unconfirmed users stay blocked.
 */
final class FacebookAuthenticator extends OAuth2Authenticator
{
    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly SocialAccountRepository $socialAccountRepository,
        private readonly UserRepository $userRepository,
        private readonly MessageBusInterface $bus,
        private readonly MetaGraphApiInterface $metaGraphApi,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_route') === 'oauth_facebook_check';
    }

    public function authenticate(Request $request): Passport
    {
        // The user cancelled the Facebook dialog (?error=access_denied, no code).
        if (!$request->query->has('code')) {
            throw new CustomUserMessageAuthenticationException('Přihlášení přes Facebook bylo zrušeno.');
        }

        $client = $this->clientRegistry->getClient('facebook_login');
        $accessToken = $this->fetchAccessToken($client);

        $facebookUser = $client->fetchUserFromToken($accessToken);
        assert($facebookUser instanceof FacebookUser);

        $providerUserId = (string) $facebookUser->getId();

        $account = $this->socialAccountRepository->findByProviderUserId(SocialProvider::Facebook, $providerUserId);

        if ($account !== null) {
            $user = $account->user;

            return new SelfValidatingPassport(new UserBadge($user->email, fn (): User => $user));
        }

        $email = $facebookUser->getEmail();

        if ($email === null || $email === '') {
            throw new CustomUserMessageAuthenticationException(
                'Facebook nám nepředal váš e-mail, bez něj vás nedokážeme spárovat s účtem. Povolte sdílení e-mailu, nebo se přihlaste heslem.',
            );
        }

        $user = $this->userRepository->findByEmailOrNull($email);

        if ($user !== null) {
            $this->linkAccount($user, $providerUserId, $facebookUser->getName(), $accessToken->getToken());

            return new SelfValidatingPassport(new UserBadge($user->email, fn (): User => $user));
        }

        $this->fileAccessRequest($email);

        throw new CustomUserMessageAuthenticationException(
            'Tento e-mail zatím nemá přístup do WBoost. Odeslali jsme za vás žádost o registraci — brzy se vám ozveme.',
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        return new RedirectResponse('/');
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $friendly = $exception instanceof CustomUserMessageAuthenticationException
            || $exception instanceof CustomUserMessageAccountStatusException;

        $session = $request->hasSession() ? $request->getSession() : null;

        if ($session instanceof Session) {
            $session->getFlashBag()->add(
                $friendly ? 'info' : 'danger',
                $friendly ? $exception->getMessageKey() : 'Přihlášení přes Facebook se nezdařilo. Zkuste to prosím znovu.',
            );
        }

        if (!$friendly) {
            $this->logger->warning('Facebook login failed.', ['exception' => $exception]);
        }

        return new RedirectResponse($this->urlGenerator->generate('login'));
    }

    /**
     * Auto-link on e-mail match. Best-effort long-lived exchange: a failure
     * must never block the login itself — without a SocialAccount row the
     * link is simply attempted again on the next Facebook login.
     */
    private function linkAccount(User $user, string $providerUserId, null|string $displayName, string $shortLivedToken): void
    {
        try {
            $longLived = $this->metaGraphApi->exchangeLongLivedUserToken($shortLivedToken);
            $scopes = $this->metaGraphApi->fetchGrantedScopes($longLived->accessToken);

            $this->bus->dispatch(new ConnectFacebookAccount(
                $user->id->toString(),
                $providerUserId,
                $longLived->accessToken,
                $longLived->expiresAtTimestamp,
                $scopes,
                $displayName,
            ));
        } catch (MetaApiError | HandlerFailedException $exception) {
            $this->logger->warning('Facebook auto-link during login failed.', ['exception' => $exception]);
        }
    }

    private function fileAccessRequest(string $email): void
    {
        try {
            $this->bus->dispatch(new RequestAccess($email));
        } catch (HandlerFailedException $exception) {
            $real = $exception->getPrevious();

            // Both are fine here: the request already exists, or a user exists
            // with this e-mail but findByEmailOrNull missed a race — either way
            // the neutral flash below is the right answer.
            if (!$real instanceof AccessAlreadyRequested && !$real instanceof EmailAlreadyRegistered) {
                throw $exception;
            }
        }
    }
}
