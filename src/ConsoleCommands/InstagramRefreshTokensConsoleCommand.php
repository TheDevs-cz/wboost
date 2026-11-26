<?php

declare(strict_types=1);

namespace WBoost\Web\ConsoleCommands;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Message\Instagram\RefreshProjectInstagramToken;
use WBoost\Web\Repository\ProjectSocialAccountRepository;
use WBoost\Web\Value\SocialPlatform;

/**
 * Keeps project Instagram connections alive: a long-lived Instagram token
 * lasts 60 days and dies for good unless refreshed, so every connection whose
 * token expires within 30 days (and is at least 24 h old — Instagram's rule)
 * gets a fresh 60-day one. Daily cron; idempotent, a no-op when nothing is
 * due. A rejected token flags the connection for reconnect.
 */
#[AsCommand('app:instagram:refresh-tokens', 'Instagram: refresh project tokens that expire within 30 days')]
final class InstagramRefreshTokensConsoleCommand extends Command
{
    public function __construct(
        readonly private ProjectSocialAccountRepository $accountRepository,
        readonly private MessageBusInterface $bus,
        readonly private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Refresh every connection (at least 24 h old), due or not');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $failed = 0;
        $ids = $this->accountRepository->connectedIds(SocialPlatform::Instagram);

        foreach ($ids as $id) {
            try {
                $this->bus->dispatch(new RefreshProjectInstagramToken($id->toString(), $force));
            } catch (\Throwable $exception) {
                $failed++;
                $this->logger->error('Instagram token refresh failed.', ['exception' => $exception, 'accountId' => $id->toString()]);
                $io->warning(sprintf('%s: %s', $id->toString(), $exception->getMessage()));
            }
        }

        $io->success(sprintf('Checked %d Instagram connections, %d failed.', count($ids), $failed));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
