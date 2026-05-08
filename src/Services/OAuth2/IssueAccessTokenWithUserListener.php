<?php

declare(strict_types=1);

namespace WBoost\Web\Services\OAuth2;

use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Server\RequestAccessTokenEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use WBoost\Web\Entity\OAuth2ClientUser;

/**
 * Sets the JWT `sub` claim of a freshly issued access token to the App User UUID
 * linked to the OAuth2 client. Without this, client_credentials tokens carry an
 * empty `oauth_user_id` and the bundle's authenticator rejects them on Symfony 8.
 */
#[AsEventListener(event: 'access_token.issued')]
final readonly class IssueAccessTokenWithUserListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(RequestAccessTokenEvent $event): void
    {
        $accessToken = $event->getAccessToken();

        if ($accessToken->getUserIdentifier() !== null) {
            return;
        }

        $clientId = $accessToken->getClient()->getIdentifier();
        $mapping = $this->entityManager->find(OAuth2ClientUser::class, $clientId);

        if ($mapping === null) {
            return;
        }

        $accessToken->setUserIdentifier($mapping->user->id->toString());
        $this->entityManager->flush();
    }
}
