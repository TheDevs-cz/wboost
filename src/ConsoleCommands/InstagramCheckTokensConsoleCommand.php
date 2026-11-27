<?php

declare(strict_types=1);

namespace WBoost\Web\ConsoleCommands;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Message\Instagram\CheckProjectInstagramToken;
use WBoost\Web\Repository\ProjectSocialAccountRepository;
use WBoost\Web\Value\SocialPlatform;

/**
 * Nightly health check of project Instagram AND Facebook Page connections
 * (their tokens don't expire, so there is nothing to refresh): a token Meta no
 * longer accepts flags the connection for reconnect, so the project page
 * shows "Znovu propojit" before anyone tries to publish. Idempotent.
 *
 * The alias `app:instagram:refresh-tokens` keeps the lily cron line from the
 * Instagram Login era working.
 */
#[AsCommand('app:instagram:check-tokens|app:instagram:refresh-tokens', 'Instagram + Facebook Page: flag project connections whose token Meta no longer accepts')]
final class InstagramCheckTokensConsoleCommand extends Command
{
    public function __construct(
        readonly private ProjectSocialAccountRepository $accountRepository,
        readonly private MessageBusInterface $bus,
        readonly private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $failed = 0;
        $ids = [
            ...$this->accountRepository->connectedIds(SocialPlatform::Instagram),
            ...$this->accountRepository->connectedIds(SocialPlatform::Facebook),
        ];

        foreach ($ids as $id) {
            try {
                $this->bus->dispatch(new CheckProjectInstagramToken($id->toString()));
            } catch (\Throwable $exception) {
                $failed++;
                $this->logger->error('Project social token check failed.', ['exception' => $exception, 'accountId' => $id->toString()]);
                $io->warning(sprintf('%s: %s', $id->toString(), $exception->getMessage()));
            }
        }

        $io->success(sprintf('Checked %d project social connections, %d failed.', count($ids), $failed));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
