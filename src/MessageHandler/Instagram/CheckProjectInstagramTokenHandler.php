<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Instagram;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\FacebookPageTokenExpired;
use WBoost\Web\Exceptions\InstagramTokenExpired;
use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Exceptions\TokenDecryptionFailed;
use WBoost\Web\Message\Instagram\CheckProjectInstagramToken;
use WBoost\Web\Repository\ProjectSocialAccountRepository;
use WBoost\Web\Services\FacebookPage\FacebookPageApiInterface;
use WBoost\Web\Services\Instagram\InstagramApiInterface;
use WBoost\Web\Services\Security\TokenCrypto;
use WBoost\Web\Value\SocialPlatform;

/**
 * A token past its expiry, one Meta rejects (190) or one that can't be
 * decrypted flags the connection for reconnect and is NOT rethrown (the flag
 * must be committed); any other API failure is rethrown so the command
 * reports it and tries again tomorrow. Handles both platforms (a Facebook
 * Page row is checked with its Page token).
 */
#[AsMessageHandler]
readonly final class CheckProjectInstagramTokenHandler
{
    public function __construct(
        private ProjectSocialAccountRepository $accountRepository,
        private InstagramApiInterface $instagramApi,
        private FacebookPageApiInterface $facebookPageApi,
        private TokenCrypto $tokenCrypto,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws MetaApiError
     */
    public function __invoke(CheckProjectInstagramToken $message): void
    {
        $account = $this->accountRepository->get(Uuid::fromString($message->accountId));

        if ($account === null || $account->needsReconnect) {
            return;
        }

        if (!$account->isUsable($this->clock->now())) {
            $account->markNeedsReconnect();

            return;
        }

        try {
            $token = $this->tokenCrypto->decrypt($account->accessToken);

            match ($account->platform) {
                SocialPlatform::Instagram => $this->instagramApi->assignedAccounts($token),
                SocialPlatform::Facebook => $this->facebookPageApi->checkPageToken($account->accountId, $token),
            };
        } catch (InstagramTokenExpired | FacebookPageTokenExpired | TokenDecryptionFailed) {
            $account->markNeedsReconnect();
        }
    }
}
