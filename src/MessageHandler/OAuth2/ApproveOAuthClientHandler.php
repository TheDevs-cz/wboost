<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\OAuth2;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\OAuthClientApproval;
use WBoost\Web\Exceptions\UserNotFound;
use WBoost\Web\Message\OAuth2\ApproveOAuthClient;
use WBoost\Web\Repository\OAuthClientApprovalRepository;
use WBoost\Web\Repository\UserRepository;

#[AsMessageHandler]
readonly final class ApproveOAuthClientHandler
{
    public function __construct(
        private OAuthClientApprovalRepository $approvals,
        private UserRepository $userRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Upsert on (user, client), which the table's unique constraint enforces:
     * a second approval of the same app must not create a second row, or
     * "covers?" would depend on which one was read.
     *
     * The pre-supplied `$approvalId` is only used when a row is actually
     * created — the caller mints it through
     * {@see \WBoost\Web\Services\ProvideIdentity} without knowing whether this
     * is a first connect or a re-approval, which is the normal shape here.
     *
     * @throws UserNotFound
     */
    public function __invoke(ApproveOAuthClient $message): void
    {
        $user = $this->userRepository->getById($message->userId);
        $now = $this->clock->now();

        $approval = $this->approvals->findFor($user, $message->clientIdentifier);

        if ($approval !== null) {
            $approval->approve($message->scopes, $now);

            return;
        }

        $this->approvals->add(new OAuthClientApproval(
            $message->approvalId,
            $user,
            $message->clientIdentifier,
            $message->scopes,
            $now,
        ));
    }
}
