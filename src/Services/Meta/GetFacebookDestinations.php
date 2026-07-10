<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Meta;

use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\SocialAccount;
use WBoost\Web\Entity\User;
use WBoost\Web\Exceptions\FacebookNotConnected;
use WBoost\Web\Exceptions\FacebookTokenExpired;
use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Exceptions\TokenDecryptionFailed;
use WBoost\Web\Message\SocialAccount\MarkSocialAccountNeedsReconnect;
use WBoost\Web\Repository\SocialAccountRepository;
use WBoost\Web\Services\Security\TokenCrypto;
use WBoost\Web\Value\FacebookPage;
use WBoost\Web\Value\SocialProvider;

/**
 * Resolves the publish destinations (Facebook Pages + their linked Instagram
 * professional accounts) for a user's connected Facebook account. Fetched
 * LIVE from the Graph API on every call — destinations are never persisted,
 * so revoked/renamed assets can't go stale. Also the single place that flags
 * a dead token as needs-reconnect.
 */
readonly final class GetFacebookDestinations
{
    public function __construct(
        private SocialAccountRepository $socialAccountRepository,
        private TokenCrypto $tokenCrypto,
        private MetaGraphApiInterface $metaGraphApi,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * The user's usable Facebook connection, or null (not connected /
     * flagged for reconnect).
     */
    public function connectedAccount(User $user): null|SocialAccount
    {
        $account = $this->socialAccountRepository->findForUser($user, SocialProvider::Facebook);

        if ($account === null || $account->needsReconnect) {
            return null;
        }

        return $account;
    }

    /**
     * @return list<FacebookPage>
     * @throws FacebookNotConnected
     * @throws MetaApiError
     */
    public function pagesFor(User $user): array
    {
        $account = $this->connectedAccount($user);

        if ($account === null) {
            throw new FacebookNotConnected();
        }

        return $this->pagesForAccount($account);
    }

    /**
     * @return list<FacebookPage>
     * @throws FacebookNotConnected
     * @throws MetaApiError
     */
    public function pagesForAccount(SocialAccount $account): array
    {
        try {
            $userToken = $this->tokenCrypto->decrypt($account->accessToken);
        } catch (TokenDecryptionFailed) {
            // Encryption key rotated — the token is unrecoverable.
            $this->bus->dispatch(new MarkSocialAccountNeedsReconnect($account->id->toString()));

            throw new FacebookNotConnected();
        }

        try {
            return $this->metaGraphApi->fetchAccounts($userToken);
        } catch (FacebookTokenExpired $exception) {
            $this->bus->dispatch(new MarkSocialAccountNeedsReconnect($account->id->toString()));

            throw $exception;
        }
    }

    /**
     * Server-side authorization of a client-picked destination: the page id
     * must be one of the user's OWN pages (never trust a posted id).
     *
     * @param list<FacebookPage> $pages
     */
    public function resolvePage(array $pages, string $pageId): null|FacebookPage
    {
        foreach ($pages as $page) {
            if ($page->id === $pageId) {
                return $page;
            }
        }

        return null;
    }
}
