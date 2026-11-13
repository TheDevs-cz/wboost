<?php

declare(strict_types=1);

namespace WBoost\Web\ConsoleCommands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WBoost\Web\Services\AiScan\AiScanAlerts;

/**
 * The operating watch of AI scan — see {@see AiScanAlerts}: evaluates every
 * operating condition from the database and e-mails the admins what is due
 * (a condition that opened, its daily reminder, its end).
 *
 * Meant for cron, every five minutes; safe to run at any time and twice at
 * once (every mail is claimed by a conditional update first). No options.
 *
 * Output: one line per action — `opened <code>`, `reminded <code>`,
 * `cleared <code>` — and nothing when there is nothing to do. Exit 0, or 1
 * when a mail could not be handed to the mailer (`failed <code> …`; its claim
 * is given back, so the next run sends it). Codes only: no secret, no
 * address, no design's text.
 */
#[AsCommand('app:ai-scan:watch', 'AI scan: evaluate the operating conditions and e-mail the admins what is due')]
final class AiScanWatchConsoleCommand extends Command
{
    public function __construct(
        readonly private AiScanAlerts $alerts,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->alerts->run();

        foreach ($result['lines'] as $line) {
            $output->writeln($line);
        }

        return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
