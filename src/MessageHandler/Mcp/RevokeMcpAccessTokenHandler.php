<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Mcp;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\McpAccessTokenNotFound;
use WBoost\Web\Message\Mcp\RevokeMcpAccessToken;
use WBoost\Web\Repository\McpAccessTokenRepository;

#[AsMessageHandler]
readonly final class RevokeMcpAccessTokenHandler
{
    public function __construct(
        private McpAccessTokenRepository $mcpAccessTokenRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The row is kept: `findActiveByHash()` filters on `revoked_at IS NULL`, so
     * a revoked token is already unusable, and deleting it would throw away the
     * only record that it was ever issued.
     *
     * @throws McpAccessTokenNotFound
     */
    public function __invoke(RevokeMcpAccessToken $message): void
    {
        $this->mcpAccessTokenRepository->get($message->tokenId)->revoke($this->clock->now());
    }
}
