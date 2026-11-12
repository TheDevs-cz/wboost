<?php

declare(strict_types=1);

namespace WBoost\Web\ConsoleCommands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use WBoost\Web\Services\AiScan\AiScanPurge;

/**
 * The nightly AI scan purge — see {@see AiScanPurge}. Idempotent and safe to
 * re-run; meant for cron (once a night).
 */
#[AsCommand('app:ai-scan:purge', 'AI scan: reap overdue scans, expire proposals past their retention, delete orphaned scan files')]
final class AiScanPurgeConsoleCommand extends Command
{
    public function __construct(
        readonly private AiScanPurge $purge,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->purge->run();

        (new SymfonyStyle($input, $output))->success(sprintf(
            'Reaped %d · expired %d · files dropped of %d accepted/discarded · %d orphan directories · %d usage rows closed · %d uploads to the bin · %d e-mails.',
            $result['reaped'],
            $result['expired'],
            $result['filesDropped'],
            $result['orphanDirectories'],
            $result['usageClosed'],
            $result['uploadsTrashed'],
            $result['emails'],
        ));

        return Command::SUCCESS;
    }
}
