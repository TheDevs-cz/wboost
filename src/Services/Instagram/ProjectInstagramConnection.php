<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Instagram;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\ProjectSocialAccount;
use WBoost\Web\Exceptions\InstagramNotConnected;
use WBoost\Web\Exceptions\InstagramTokenExpired;
use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Exceptions\TokenDecryptionFailed;
use WBoost\Web\Message\Project\MarkProjectSocialAccountNeedsReconnect;
use WBoost\Web\Repository\ProjectSocialAccountRepository;
use WBoost\Web\Services\Security\TokenCrypto;
use WBoost\Web\Value\SocialPlatform;

/**
 * A project's Instagram connection as publishing needs it: the stored row, a
 * usable plaintext token, and the single place that flags a dead token for
 * reconnect.
 */
readonly final class ProjectInstagramConnection
{
    public function __construct(
        private ProjectSocialAccountRepository $accountRepository,
        private TokenCrypto $tokenCrypto,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
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
