<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Mcp;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\McpAccessToken;
use WBoost\Web\Exceptions\UserNotFound;
use WBoost\Web\Mcp\Security\McpTokenGenerator;
use WBoost\Web\Message\Mcp\CreateMcpAccessToken;
use WBoost\Web\Repository\McpAccessTokenRepository;
use WBoost\Web\Repository\UserRepository;

#[AsMessageHandler]
readonly final class CreateMcpAccessTokenHandler
{
    public function __construct(
        private McpAccessTokenRepository $mcpAccessTokenRepository,
        private UserRepository $userRepository,
        private McpTokenGenerator $tokenGenerator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Hashing here — rather than in the caller — is what keeps the wire format
     * a secret of {@see McpTokenGenerator}: the plaintext arrives, the sha256
     * is what lands in the row, and nothing on this path can persist the
     * secret by accident.
     *
     * Scopes are stored EXACTLY as chosen, never pre-expanded through
     * `McpScope::grants()`. Implication is evaluated at check time on purpose —
     * a stored expansion would freeze today's implication graph into every row
     * and quietly go stale the moment it changes.
     *
     * @throws UserNotFound
     */
    public function __invoke(CreateMcpAccessToken $message): void
    {
        $user = $this->userRepository->getById($message->userId);

        $this->mcpAccessTokenRepository->add(
            new McpAccessToken(
                $message->tokenId,
                $user,
                $message->name,
                $message->scopes,
                $this->tokenGenerator->hash($message->plainTextToken),
                $this->clock->now(),
            ),
        );
    }
}
