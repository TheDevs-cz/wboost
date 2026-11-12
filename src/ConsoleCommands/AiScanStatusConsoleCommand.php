<?php

declare(strict_types=1);

namespace WBoost\Web\ConsoleCommands;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
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
use WBoost\Web\Services\AiScan\AiScanTransitions;
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
 * And the scans themselves — what the runbook (docs/ai-scan/runbook.md) works
 * with:
 *
 *     bin/console app:ai-scan:status --scans             # every scan in progress + the last ended ones
 *     bin/console app:ai-scan:status --scan=<id>         # one scan: history, usage rows (spend), ledger
 *     bin/console app:ai-scan:status --cancel=<id>       # cancel a scan in progress, as its user would
 *
 * Ids, codes, numbers and e-mail addresses only — never a design's text.
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
        readonly private AiScanTransitions $transitions,
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
            ->addOption('reap', null, InputOption::VALUE_NONE, 'Run the reaper now')
            ->addOption('scans', null, InputOption::VALUE_NONE, 'List the scans in progress and the last ended ones')
            ->addOption('scan', null, InputOption::VALUE_REQUIRED, 'Show one scan: history, usage rows, ledger')
            ->addOption('cancel', null, InputOption::VALUE_REQUIRED, 'Cancel a scan in progress (refunded while it waits, counted while it runs)');
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

        $cancel = self::option($input, 'cancel');

        if ($cancel !== null) {
            if (!Uuid::isValid($cancel)) {
                $io->error('--cancel takes a scan id.');

                return Command::INVALID;
            }

            // The same conditional transition the user's "Zrušit sken" makes.
            $this->transitions->cancel($cancel)
                ? $io->success('Scan cancelled. A running scanner learns it from its next heartbeat (≤ 15 s).')
                : $io->warning('Nothing cancelled: the scan is not in progress (or does not exist).');
        }

        $scan = self::option($input, 'scan') ?? $cancel;

        if ($scan !== null) {
            return $this->showScan($io, $scan);
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

        if ($input->getOption('scans') === true) {
            $this->listScans($io);
        }

        return Command::SUCCESS;
    }

    /**
     * Every scan in progress, oldest first, then the last ended ones.
     */
    private function listScans(SymfonyStyle $io): void
    {
        $columns = "s.id, p.name AS project, s.requester_email, s.status, COALESCE(s.error_code, '') AS code, COALESCE(s.stage, '') AS stage,
                    s.try, s.run, s.intake_tries, s.auto_restart,
                    EXTRACT(EPOCH FROM (NOW() - COALESCE(s.waiting_since, s.claimed_at, s.created_at)))::int AS age,
                    CASE WHEN s.lease_expires_at IS NULL THEN NULL ELSE EXTRACT(EPOCH FROM (s.lease_expires_at - NOW()))::int END AS lease,
                    s.cost_micro_usd, s.finished_at";

        $inProgress = $this->connection->fetchAllAssociative(
            "SELECT $columns FROM ai_scan s JOIN project p ON p.id = s.project_id
              WHERE s.status IN ('checking', 'queued', 'running') ORDER BY COALESCE(s.waiting_since, s.claimed_at, s.created_at), s.id",
        );
        $ended = $this->connection->fetchAllAssociative(
            "SELECT $columns FROM ai_scan s JOIN project p ON p.id = s.project_id
              WHERE s.status NOT IN ('checking', 'queued', 'running') ORDER BY s.finished_at DESC NULLS LAST, s.id DESC LIMIT 15",
        );

        $row = static fn (array $scan): array => [
            self::cell($scan['id']),
            mb_substr(self::cell($scan['project']), 0, 24),
            self::cell($scan['requester_email']),
            self::cell($scan['status']) . (self::cell($scan['code']) !== '' ? ' ' . self::cell($scan['code']) : ''),
            self::cell($scan['stage']),
            sprintf('%s / %s%s', self::cell($scan['try']), self::cell($scan['run']), (int) self::cell($scan['auto_restart']) >= 1 ? ' (restarted)' : ''),
            in_array($scan['status'], ['checking', 'queued', 'running'], true) ? self::cell($scan['age']) . ' s' : self::cell($scan['finished_at']),
            // (A finished row keeps its last lease time; it means nothing there.)
            $scan['lease'] === null || !in_array($scan['status'], ['checking', 'queued', 'running'], true) ? '—' : self::cell($scan['lease']) . ' s',
            sprintf('$%.4f', (int) self::cell($scan['cost_micro_usd']) / 1_000_000),
        ];
        $headers = ['Scan', 'Project', 'Requested by', 'Status', 'Stage', 'Try / run', 'In this state / ended', 'Lease ends in', 'Spend'];

        $io->section(sprintf('In progress (%d)', count($inProgress)));
        $inProgress === [] ? $io->writeln('  none') : $io->table($headers, array_map($row, $inProgress));
        $io->section('Last ended');
        $ended === [] ? $io->writeln('  none') : $io->table($headers, array_map($row, $ended));
    }

    /**
     * One scan: where it is, what happened to it, what it cost (one usage
     * row per run and step — a killed run's row is closed with its last
     * figures) and what it did to the credit.
     */
    private function showScan(SymfonyStyle $io, string $id): int
    {
        $scan = Uuid::isValid($id) ? $this->connection->fetchAssociative(
            "SELECT s.id, s.project_id, p.name AS project, s.requester_email, s.channel, s.kind, s.status, s.error_code, s.stage, s.note, s.try, s.run, s.intake_tries,
                    s.intake_losses, s.auto_restart, s.failure_count, s.created_at, s.waiting_since, s.claimed_at, s.lease_expires_at, s.finished_at, s.expires_at,
                    s.scanner_version, s.definition_version, s.cost_micro_usd, s.turns, s.kept_candidate, jsonb_array_length(s.candidates) AS candidates, s.events
               FROM ai_scan s JOIN project p ON p.id = s.project_id WHERE s.id = :id",
            ['id' => $id],
        ) : false;

        if ($scan === false) {
            $io->error('No such scan.');

            return Command::FAILURE;
        }

        $pairs = [];

        foreach ($scan as $name => $value) {
            if ($name !== 'events') {
                $pairs[] = [$name => $value === null ? '—' : self::cell($value)];
            }
        }

        $io->definitionList(...$pairs);

        $events = is_string($scan['events']) ? json_decode($scan['events'], true) : null;
        $io->section('History');
        $io->writeln(is_array($events) && $events !== []
            ? array_map(static fn (mixed $event): string => '  ' . (is_array($event) ? trim(sprintf('%s  %s  %s → %s  %s', self::cell($event['at'] ?? ''), self::cell($event['event'] ?? ''), self::cell($event['from'] ?? ''), self::cell($event['to'] ?? ''), self::cell($event['code'] ?? ''))) : ''), $events)
            : '  —');

        $usage = $this->connection->fetchAllAssociative(
            "SELECT run, step, model, COALESCE(outcome, 'OPEN') AS outcome, COALESCE(error_code, '') AS code, input_tokens, output_tokens, cache_read_tokens,
                    cache_write_tokens, cache_write_1h_tokens, turns, cost_micro_usd, engine_cost_micro_usd, occurred_at, closed_at
               FROM ai_usage_event WHERE scan_id = :id ORDER BY occurred_at, run",
            ['id' => $id],
        );
        $io->section('Usage — what each run of each step spent');
        $usage === [] ? $io->writeln('  none') : $io->table(
            ['Step', 'Run', 'Model', 'Outcome', 'In', 'Out', 'Cache read', 'Cache write (1 h)', 'Turns', 'Recorded', 'Engine', 'Opened', 'Closed'],
            array_map(static fn (array $row): array => [
                self::cell($row['step']), self::cell($row['run']), self::cell($row['model']), trim(self::cell($row['outcome']) . ' ' . self::cell($row['code'])),
                self::cell($row['input_tokens']), self::cell($row['output_tokens']), self::cell($row['cache_read_tokens']),
                sprintf('%s (%s)', self::cell($row['cache_write_tokens']), self::cell($row['cache_write_1h_tokens'])), self::cell($row['turns']),
                sprintf('$%.4f', (int) self::cell($row['cost_micro_usd']) / 1_000_000), sprintf('$%.4f', (int) self::cell($row['engine_cost_micro_usd']) / 1_000_000),
                self::cell($row['occurred_at']), $row['closed_at'] === null ? 'OPEN' : self::cell($row['closed_at']),
            ], $usage),
        );

        /** @var list<string> $ledger */
        $ledger = $this->connection->fetchFirstColumn(
            "SELECT 'try ' || try || ': ' || reason || ' ' || delta || ' (' || period || ', ' || created_at || ')' FROM ai_credit_ledger
              WHERE scan_id = :id ORDER BY try, created_at, CASE reason WHEN 'reserve' THEN 0 ELSE 1 END",
            ['id' => $id],
        );
        $io->section('Credit');
        $io->writeln($ledger === [] ? '  —' : array_map(static fn (string $line): string => '  ' . $line, $ledger));

        return Command::SUCCESS;
    }

    private static function cell(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
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
