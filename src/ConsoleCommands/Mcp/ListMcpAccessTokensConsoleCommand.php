<?php

declare(strict_types=1);

namespace WBoost\Web\ConsoleCommands\Mcp;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use WBoost\Web\Entity\McpAccessToken;
use WBoost\Web\Repository\McpAccessTokenRepository;

/**
 * Every MCP personal access token ever issued, with its status.
 *
 * Nothing secret is printed — not the hash either. The only identifier a token
 * has outside its owner's config is its id, which is what
 * `app:mcp:token:revoke` takes.
 */
#[AsCommand('app:mcp:token:list', 'List MCP access tokens (secrets are never shown)')]
final class ListMcpAccessTokensConsoleCommand extends Command
{
    public function __construct(
        readonly private McpAccessTokenRepository $mcpAccessTokenRepository,
        readonly private ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tokens = $this->mcpAccessTokenRepository->listAll();

        if ($tokens === []) {
            $io->info('No MCP access tokens issued.');

            return self::SUCCESS;
        }

        $now = $this->clock->now();
        $rows = [];

        foreach ($tokens as $token) {
            $rows[] = [
                $token->id->toString(),
                $token->user->email,
                $token->name,
                implode(', ', $token->scopes),
                self::status($token, $now),
                $token->createdAt->format('Y-m-d H:i'),
                $token->lastUsedAt?->format('Y-m-d H:i') ?? '—',
            ];
        }

        $io->table(['ID', 'User', 'Name', 'Scopes', 'Status', 'Created', 'Last used'], $rows);

        return self::SUCCESS;
    }

    private static function status(McpAccessToken $token, DateTimeImmutable $now): string
    {
        if ($token->revokedAt !== null) {
            return sprintf('revoked %s', $token->revokedAt->format('Y-m-d H:i'));
        }

        // Whatever is left after the revoked branch can only fail isActive() by
        // having passed its expiry — the two conditions are the same predicate
        // findActiveByHash() runs, read from the other side.
        if (!$token->isActive($now)) {
            return sprintf('expired %s', $token->expiresAt?->format('Y-m-d H:i') ?? '');
        }

        return 'active';
    }
}
