<?php

declare(strict_types=1);

namespace WBoost\Web\ConsoleCommands\Mcp;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Exceptions\McpAccessTokenNotFound;
use WBoost\Web\Message\Mcp\RevokeMcpAccessToken;
use WBoost\Web\Repository\McpAccessTokenRepository;

/**
 * Kills one MCP personal access token. The very next `/_mcp` request carrying
 * it is answered with the 401 challenge — the firewall is stateless, so there
 * is no session left holding the door open.
 */
#[AsCommand('app:mcp:token:revoke', 'Revoke an MCP access token so it can no longer authenticate')]
final class RevokeMcpAccessTokenConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private McpAccessTokenRepository $mcpAccessTokenRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('token-id', InputArgument::REQUIRED, 'Id of the token to revoke (see app:mcp:token:list)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $rawTokenId */
        $rawTokenId = $input->getArgument('token-id');

        if (!Uuid::isValid($rawTokenId)) {
            $io->error(sprintf('"%s" is not a valid token id — pass the ID column of app:mcp:token:list.', $rawTokenId));

            return self::FAILURE;
        }

        // Looked up before dispatching so a typo answers with a clear "not
        // found" and a non-zero exit, rather than a HandlerFailedException
        // stack trace wrapping McpAccessTokenNotFound.
        try {
            $token = $this->mcpAccessTokenRepository->get(Uuid::fromString($rawTokenId));
        } catch (McpAccessTokenNotFound) {
            $io->error(sprintf('MCP access token "%s" was not found.', $rawTokenId));

            return self::FAILURE;
        }

        if ($token->isRevoked()) {
            $io->info(sprintf(
                'Token "%s" (%s) was already revoked on %s.',
                $token->name,
                $token->user->email,
                // Never null once isRevoked() is true; the fallback exists
                // because a predicate method narrows nothing for the analyser.
                $token->revokedAt?->format('Y-m-d H:i') ?? '—',
            ));

            return self::SUCCESS;
        }

        $this->messageBus->dispatch(new RevokeMcpAccessToken($token->id));

        $io->success(sprintf('Token "%s" of %s revoked.', $token->name, $token->user->email));

        return self::SUCCESS;
    }
}
