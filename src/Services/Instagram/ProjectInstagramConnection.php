<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Instagram;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\ProjectSocialAccount;
use WBoost\Web\Exceptions\InstagramNotConnected;
use WBoost\Web\Exceptions\InstagramTokenExpired;
use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Exceptions\TokenDecryptionFailed;
use WBoost\Web\Message\Instagram\RefreshProjectInstagramToken;
use WBoost\Web\Message\Project\MarkProjectSocialAccountNeedsReconnect;
use WBoost\Web\Repository\ProjectSocialAccountRepository;
use WBoost\Web\Services\Security\TokenCrypto;
use WBoost\Web\Value\SocialPlatform;

/**
 * A project's Instagram connection as publishing needs it: the stored row, a
 * usable plaintext token (refreshed on the way when it is close to expiry —
 * the daily cron is the primary refresher, this is the safety net), and the
 * single place that flags a dead token for reconnect.
 */
readonly final class ProjectInstagramConnection
{
    /** Refresh at publish time once the token is this close to expiry. */
    private const string LAZY_REFRESH_WINDOW = '+7 days';

    public function __construct(
        private ProjectSocialAccountRepository $accountRepository,
        private TokenCrypto $tokenCrypto,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function find(Project|UuidInterface $project): null|ProjectSocialAccount
    {
        return $this->accountRepository->findForProject($project, SocialPlatform::Instagram);
    }

    /**
     * The usable connection, or null (never connected / needs reconnect /
     * token past its expiry).
     */
    public function usable(Project $project): null|ProjectSocialAccount
    {
        $account = $this->find($project);

        return $account !== null && $account->isUsable($this->clock->now()) ? $account : null;
    }

    /**
     * @throws InstagramNotConnected
     * @throws InstagramTokenExpired
     */
    public function accessToken(ProjectSocialAccount $account): string
    {
        $now = $this->clock->now();

        if (!$account->isUsable($now)) {
            throw new InstagramNotConnected();
        }

        if ($account->isDueForRefresh($now, self::LAZY_REFRESH_WINDOW)) {
            try {
                $this->bus->dispatch(new RefreshProjectInstagramToken($account->id->toString()));
            } catch (\Throwable $exception) {
                // The current token is still valid — publish with it, the
                // cron retries the refresh tonight.
                $this->logger->warning('Instagram token refresh before publishing failed.', ['exception' => $exception]);
            }

            if ($account->needsReconnect) {
                throw new InstagramTokenExpired('The Instagram token was rejected during refresh.');
            }
        }

        try {
            return $this->tokenCrypto->decrypt($account->accessToken);
        } catch (TokenDecryptionFailed) {
            // Encryption key rotated — the token is unrecoverable.
            $this->markNeedsReconnect($account);

            throw new InstagramTokenExpired('The stored Instagram token cannot be decrypted.');
        }
    }

    public function markNeedsReconnect(ProjectSocialAccount $account): void
    {
        $this->bus->dispatch(new MarkProjectSocialAccountNeedsReconnect($account->id->toString()));
    }

    /**
     * @throws MetaApiError
     */
    public function handleApiError(ProjectSocialAccount $account, MetaApiError $error): never
    {
        if ($error instanceof InstagramTokenExpired) {
            $this->markNeedsReconnect($account);
        }

        throw $error;
    }
}
