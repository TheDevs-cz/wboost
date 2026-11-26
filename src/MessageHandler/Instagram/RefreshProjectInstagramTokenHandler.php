<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Instagram;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\InstagramTokenExpired;
use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Exceptions\TokenDecryptionFailed;
use WBoost\Web\Message\Instagram\RefreshProjectInstagramToken;
use WBoost\Web\Repository\ProjectSocialAccountRepository;
use WBoost\Web\Services\Instagram\InstagramApiInterface;
use WBoost\Web\Services\Security\TokenCrypto;

/**
 * Refreshes a due token. A dead token (190) or an undecryptable one flags the
 * connection for reconnect and is NOT rethrown (the flag must be committed);
 * any other API failure is rethrown so the caller can log / retry tomorrow.
 */
#[AsMessageHandler]
readonly final class RefreshProjectInstagramTokenHandler
{
    public function __construct(
        private ProjectSocialAccountRepository $accountRepository,
        private InstagramApiInterface $instagramApi,
        private TokenCrypto $tokenCrypto,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws MetaApiError
     */
    public function __invoke(RefreshProjectInstagramToken $message): void
    {
        $account = $this->accountRepository->get(Uuid::fromString($message->accountId));
        $now = $this->clock->now();

        if ($account === null || $account->needsReconnect) {
            return;
        }

        $due = $message->force
            ? $account->tokenIssuedAt <= $now->modify('-24 hours')
            : $account->isDueForRefresh($now);

        if (!$due) {
            return;
        }

        try {
            $token = $this->instagramApi->refreshToken($this->tokenCrypto->decrypt($account->accessToken));
        } catch (InstagramTokenExpired | TokenDecryptionFailed) {
            $account->markNeedsReconnect();

            return;
        }

        $account->refreshToken(
            $this->tokenCrypto->encrypt($token->accessToken),
            $token->expiresAtTimestamp !== null ? DateTimeImmutable::createFromTimestamp($token->expiresAtTimestamp) : null,
            $now,
        );
    }
}
