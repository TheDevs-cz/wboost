<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\FacebookPage;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\ProjectSocialAccount;
use WBoost\Web\Message\FacebookPage\ConnectProjectFacebookPage;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Repository\ProjectSocialAccountRepository;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\TokenCrypto;
use WBoost\Web\Value\SocialPlatform;

/**
 * accountId = Page id, accountUserId = the token owner (system user) id,
 * name = Page name (username stays null — Pages have no @handle here).
 */
#[AsMessageHandler]
readonly final class ConnectProjectFacebookPageHandler
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private UserRepository $userRepository,
        private ProjectSocialAccountRepository $accountRepository,
        private TokenCrypto $tokenCrypto,
        private ProvideIdentity $provideIdentity,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ConnectProjectFacebookPage $message): void
    {
        $project = $this->projectRepository->get(Uuid::fromString($message->projectId));
        $user = $this->userRepository->getById(Uuid::fromString($message->userId));
        $encryptedToken = $this->tokenCrypto->encrypt($message->pageAccessToken);
        $expiresAt = $message->tokenExpiresAtTimestamp !== null
            ? DateTimeImmutable::createFromTimestamp($message->tokenExpiresAtTimestamp)
            : null;
        $now = $this->clock->now();

        $existing = $this->accountRepository->findForProject($project, SocialPlatform::Facebook);

        if ($existing !== null) {
            $existing->reconnect($message->pageId, $message->tokenOwnerId, null, $message->pageName, null, $encryptedToken, $expiresAt, [], $user, $now);

            return;
        }

        $this->accountRepository->save(new ProjectSocialAccount(
            $this->provideIdentity->next(),
            $project,
            SocialPlatform::Facebook,
            $message->pageId,
            $message->tokenOwnerId,
            null,
            $message->pageName,
            null,
            $encryptedToken,
            $expiresAt,
            [],
            $user,
            $now,
        ));
    }
}
