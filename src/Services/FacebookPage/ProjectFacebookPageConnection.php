<?php

declare(strict_types=1);

namespace WBoost\Web\Services\FacebookPage;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\ProjectSocialAccount;
use WBoost\Web\Exceptions\FacebookPageNotConnected;
use WBoost\Web\Exceptions\FacebookPageTokenExpired;
use WBoost\Web\Exceptions\TokenDecryptionFailed;
use WBoost\Web\Message\Project\MarkProjectSocialAccountNeedsReconnect;
use WBoost\Web\Repository\ProjectSocialAccountRepository;
use WBoost\Web\Services\Security\TokenCrypto;
use WBoost\Web\Value\SocialPlatform;

/**
 * A project's Facebook Page connection as publishing needs it: the stored
 * row (accountId = Page id, accessToken = encrypted Page token), the
 * plaintext token, and flagging a dead token for reconnect.
 */
readonly final class ProjectFacebookPageConnection
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
        return $this->accountRepository->findForProject($project, SocialPlatform::Facebook);
    }

    public function usable(Project $project): null|ProjectSocialAccount
    {
        $account = $this->find($project);

        return $account !== null && $account->isUsable($this->clock->now()) ? $account : null;
    }

    /**
     * @throws FacebookPageNotConnected
     * @throws FacebookPageTokenExpired
     */
    public function accessToken(ProjectSocialAccount $account): string
    {
        if (!$account->isUsable($this->clock->now())) {
            throw new FacebookPageNotConnected();
        }

        try {
            return $this->tokenCrypto->decrypt($account->accessToken);
        } catch (TokenDecryptionFailed) {
            $this->markNeedsReconnect($account);

            throw new FacebookPageTokenExpired('The stored Facebook Page token cannot be decrypted.');
        }
    }

    public function markNeedsReconnect(ProjectSocialAccount $account): void
    {
        $this->bus->dispatch(new MarkProjectSocialAccountNeedsReconnect($account->id->toString()));
    }
}
