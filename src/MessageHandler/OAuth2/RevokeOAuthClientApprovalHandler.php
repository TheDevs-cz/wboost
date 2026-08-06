<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\OAuth2;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\UserNotFound;
use WBoost\Web\Message\OAuth2\RevokeOAuthClientApproval;
use WBoost\Web\Repository\OAuthClientApprovalRepository;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Services\OAuth2\RevokeUserClientCredentials;

#[AsMessageHandler]
readonly final class RevokeOAuthClientApprovalHandler
{
    public function __construct(
        private OAuthClientApprovalRepository $approvals,
        private UserRepository $userRepository,
        private RevokeUserClientCredentials $revokeCredentials,
    ) {
    }

    /**
     * Credentials are revoked whether or not an approval row was found: the row
     * is only the memory of a decision, while the tokens are the access itself,
     * and a request to disconnect must end the access under every ordering
     * (including tokens issued before this feature existed, which have no
     * approval row at all).
     *
     * @throws UserNotFound
     */
    public function __invoke(RevokeOAuthClientApproval $message): void
    {
        $user = $this->userRepository->getById($message->userId);

        $approval = $this->approvals->findFor($user, $message->clientIdentifier);

        if ($approval !== null) {
            $this->approvals->remove($approval);
        }

        $this->revokeCredentials->revoke($user, $message->clientIdentifier);
    }
}
