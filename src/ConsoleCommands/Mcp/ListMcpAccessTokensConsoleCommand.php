<?php

declare(strict_types=1);

namespace WBoost\Web\ConsoleCommands\Mcp;

use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
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
    private const string DATE_FORMAT = 'Y-m-d H:i';

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
            // The status DECISION lives on the entity; all that happens here is
            // pinning the label to the instant that explains it ("revoked
            // 2026-08-05 12:00"), which is presentation.
            $status = $token->status($now);
            $changedAt = $token->statusChangedAt($now);

            $rows[] = [
                $token->id->toString(),
                $token->user->email,
                $token->name,
                implode(', ', $token->scopes),
                $changedAt === null
                    ? $status->value
                    : sprintf('%s %s', $status->value, $changedAt->format(self::DATE_FORMAT)),
                $token->createdAt->format(self::DATE_FORMAT),
                $token->lastUsedAt?->format(self::DATE_FORMAT) ?? '—',
            ];
        }

        $io->table(['ID', 'User', 'Name', 'Scopes', 'Status', 'Created', 'Last used'], $rows);

        return self::SUCCESS;
    }
}
