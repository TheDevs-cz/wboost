<?php

declare(strict_types=1);

namespace WBoost\Web\ConsoleCommands;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Message\AiScan\SetAiScanRuntime;
use WBoost\Web\Services\AiScan\AiScanDefinition;
use WBoost\Web\Services\AiScan\AiScanReaper;
use WBoost\Web\Services\AiScan\AiScanRuntimeState;
use WBoost\Web\Services\AiScan\AiScanSpend;
use WBoost\Web\Services\AiScan\AiScanStorage;
use WBoost\Web\Value\AiScan\AiScanMode;

/**
 * Shows how AI scan stands — operating mode, scanner, provider, queue, today's
 * spend — and, with options, changes the runtime row. The row is in the
 * database, so a change takes effect on the next request, without a deploy:
 *
 *     bin/console app:ai-scan:status --mode=paused
 *     bin/console app:ai-scan:status --mode=off          # fails scans in progress, refunded
 *     bin/console app:ai-scan:status --admin-only=0 --max-running=2
 *
 * (The admin screen for the same switches comes with the admin tab.)
 */
#[AsCommand('app:ai-scan:status', 'AI scan: show the operating state; change mode and limits with options')]
final class AiScanStatusConsoleCommand extends Command
{
    public function __construct(
        readonly private AiScanRuntimeState $runtime,
        readonly private AiScanSpend $spend,
        readonly private AiScanReaper $reaper,
        readonly private AiScanStorage $storage,
        readonly private AiScanDefinition $definition,
        readonly private MessageBusInterface $bus,
        readonly private Connection $connection,
        readonly private ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'on | paused | off')
            ->addOption('admin-only', null, InputOption::VALUE_REQUIRED, '1 = only admins may start scans, 0 = editors of entitled projects too')
            ->addOption('max-running', null, InputOption::VALUE_REQUIRED, 'Builds running at once')
            ->addOption('daily-ceiling-usd', null, InputOption::VALUE_REQUIRED, 'Spend ceiling per day, in USD')
            ->addOption('project-ceiling-usd', null, InputOption::VALUE_REQUIRED, 'Spend ceiling per project per day, in USD')
            ->addOption('daily-starts-user', null, InputOption::VALUE_REQUIRED, 'Starts a day per user')
            ->addOption('daily-starts-project', null, InputOption::VALUE_REQUIRED, 'Starts a day per project')
            ->addOption('reap', null, InputOption::VALUE_NONE, 'Run the reaper now');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $mode = null;
        $modeOption = self::option($input, 'mode');

        if ($modeOption !== null) {
            $mode = AiScanMode::tryFrom($modeOption);

            if ($mode === null) {
                $io->error('--mode must be on, paused or off.');

                return Command::INVALID;
            }
        }

        $adminOnly = self::option($input, 'admin-only');
        $change = new SetAiScanRuntime(
            mode: $mode,
            adminOnly: $adminOnly === null ? null : in_array($adminOnly, ['1', 'true', 'yes', 'on'], true),
            maxRunning: self::intOption($input, 'max-running'),
            dailyCeilingMicroUsd: self::usdOption($input, 'daily-ceiling-usd'),
            projectDailyMicroUsd: self::usdOption($input, 'project-ceiling-usd'),
            dailyStartsUser: self::intOption($input, 'daily-starts-user'),
            dailyStartsProject: self::intOption($input, 'daily-starts-project'),
        );

        if ($change != new SetAiScanRuntime()) {
            $this->bus->dispatch($change);
            $io->success('Runtime row updated — effective on the next request.');
        }

        if ($input->getOption('reap') === true) {
            $io->writeln(sprintf('Reaper moved %d scan(s).', $this->reaper->run()));
        }

        $now = $this->clock->now();
        $runtime = $this->runtime->get();

        /** @var array<string, int|string> $counts */
        $counts = $this->connection->fetchAllKeyValue('SELECT status, COUNT(*) FROM ai_scan GROUP BY status');
        $oldest = $this->connection->fetchOne("SELECT MIN(waiting_since) FROM ai_scan WHERE status IN ('checking', 'queued')");
        $openUsage = $this->connection->fetchOne('SELECT COUNT(*) FROM ai_usage_event WHERE closed_at IS NULL');

        $io->definitionList(
            ['Mode' => $runtime->mode->value . ($runtime->adminOnly ? ' · admins only' : ' · admins + editors of entitled projects')],
            ['Waiting reason' => ($waiting = $runtime->waiting($now)) === null ? '—' : $waiting->value],
            ['Scanner' => $runtime->scannerSeenAt === null
                ? 'never seen'
                : sprintf(
                    'seen %d s ago (version %s)%s',
                    $now->getTimestamp() - $runtime->scannerSeenAt->getTimestamp(),
                    $runtime->scannerVersion ?? '?',
                    $runtime->scannerTooOld ? ' · TOO OLD' : '',
                )],
            ['Provider' => $runtime->providerState->value . ($runtime->providerError !== null ? ' · ' . $runtime->providerError : '')],
            ['Circuit breaker' => sprintf('%d consecutive %s', $runtime->consecutiveFailures, $runtime->failureClass ?? 'failures')],
            ['Scans' => $counts === [] ? 'none' : implode(' · ', array_map(
                static fn (string $status, int|string $count): string => $status . ' ' . $count,
                array_keys($counts),
                $counts,
            ))],
            ['Oldest waiting since' => is_string($oldest) ? $oldest : '—'],
            ['Open usage rows' => is_numeric($openUsage) ? (string) $openUsage : '0'],
            ['Spend today' => sprintf(
                '$%.2f of $%.2f (of that $%.2f reserved by running scans) · project ceiling $%.2f',
                $this->spend->totalToday($now) / 1_000_000,
                $runtime->dailyCeilingMicroUsd / 1_000_000,
                $this->spend->reservedNow() / 1_000_000,
                $runtime->projectDailyMicroUsd / 1_000_000,
            )],
            ['Limits' => sprintf('max running %d · starts/day user %d, project %d', $runtime->maxRunning, $runtime->dailyStartsUser, $runtime->dailyStartsProject)],
            ['Definition' => sprintf('%s · protocol %d', $this->definition->version(), $this->definition->protocolVersion())],
            ['Private bucket' => $this->storage->reachable() ? 'reachable' : 'NOT reachable'],
        );

        return Command::SUCCESS;
    }

    private static function option(InputInterface $input, string $name): null|string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function intOption(InputInterface $input, string $name): null|int
    {
        $value = self::option($input, $name);

        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }

    private static function usdOption(InputInterface $input, string $name): null|int
    {
        $value = self::option($input, $name);

        return $value !== null && is_numeric($value) ? (int) round((float) $value * 1_000_000) : null;
    }
}
