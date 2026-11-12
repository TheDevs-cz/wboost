<?php

declare(strict_types=1);

namespace WBoost\Web\ConsoleCommands;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use WBoost\Web\Message\AiScan\CancelAiScan;
use WBoost\Web\Message\AiScan\ContinueAiScan;
use WBoost\Web\Message\AiScan\DiscardAiScan;
use WBoost\Web\Message\AiScan\RequestAiScan;
use WBoost\Web\Message\AiScan\RetryAiScan;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Services\AiScan\AiScanDefinition;
use WBoost\Web\Services\AiScan\AiScanPurge;
use WBoost\Web\Services\AiScan\AiScanStandIn;
use WBoost\Web\Services\AiScan\AiScanStorage;
use WBoost\Web\Services\AiScan\AiScanTransitions;
use WBoost\Web\Services\AiScan\ScannerApi;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Value\AiScan\AiScanChannel;
use WBoost\Web\Value\AiScan\AiScanKind;
use WBoost\Web\Value\AiScan\AiScanRefused;

/**
 * A FAKE SCANNER, for development and acceptance — it calls no provider and
 * decodes no picture. It plays both sides of a scan:
 *
 * - the **user**, through the same messages the web controllers dispatch
 *   (upload, cancel, retry, continue, discard);
 * - the **scanner**, through the real `/internal/scanner/*` endpoints,
 *   in-process (so the source-network check, the token, the lease token and
 *   the protocol header are all exercised). It is the executable example of
 *   the wire contract.
 *
 * Scenarios: `happy` (→ ready), `stop` (→ stopped → "Zkusit přesto" → ready),
 * `fail` (→ failed → "Zkusit znovu" → ready), `refuse`, `cancel`, `hang`
 * (claims a build, sends `--beats` heartbeats, then stops answering — kill
 * it, or let it end: the lease runs out and the reaper restarts the scan) and
 * `all`, which walks every state of the table once (including `accepted`,
 * `discarded` and `expired`).
 *
 * Needs the operating mode `on` (`app:ai-scan:status --mode=on`) and an admin
 * (or an entitled editor) as `--user`. **Refuses to run in production.**
 */
#[AsCommand('app:ai-scan:fake-run', 'AI scan: drive scans through their states with a fake scanner (never in production)')]
final class AiScanFakeRunConsoleCommand extends Command
{
    private const string SCANNER_VERSION = 'fake-1';

    private int $seq = 0;

    public function __construct(
        readonly private HttpKernelInterface $httpKernel,
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
        readonly private UserRepository $userRepository,
        readonly private GetFonts $getFonts,
        readonly private AiScanStorage $storage,
        readonly private AiScanTransitions $transitions,
        readonly private AiScanPurge $purge,
        readonly private AiScanDefinition $definition,
        readonly private Connection $connection,
        #[Autowire('%kernel.environment%')]
        readonly private string $environment,
        #[Autowire('%env(SCANNER_TOKEN)%')]
        readonly private string $scannerToken,
        #[Autowire('%env(SCAN_NETWORK_CIDR)%')]
        readonly private string $scanNetworkCidr,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('project', InputArgument::REQUIRED, 'Project id')
            ->addArgument('user', InputArgument::REQUIRED, 'E-mail of the user who starts the scans')
            ->addOption('scenario', null, InputOption::VALUE_REQUIRED, 'happy | stop | fail | refuse | cancel | hang | all', 'happy')
            ->addOption('image', null, InputOption::VALUE_REQUIRED, 'A picture to upload (default: a generated 900 × 1200 PNG)')
            ->addOption('beats', null, InputOption::VALUE_REQUIRED, 'Heartbeats per build', '3')
            ->addOption('beat-interval', null, InputOption::VALUE_REQUIRED, 'Seconds between heartbeats', '0')
            ->addOption('no-render', null, InputOption::VALUE_NONE, 'Skip the candidate render (no Gotenberg needed); the scan then fails as build');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->environment === 'prod') {
            $io->error('app:ai-scan:fake-run never runs in production.');

            return Command::FAILURE;
        }

        if (trim($this->scannerToken) === '' || trim($this->scanNetworkCidr) === '') {
            $io->error('SCANNER_TOKEN / SCAN_NETWORK_CIDR are not set — the scanner endpoints answer 404 to everyone (see .env.dev).');

            return Command::FAILURE;
        }

        $project = $input->getArgument('project');
        $email = $input->getArgument('user');

        if (!is_string($project) || !Uuid::isValid($project) || !is_string($email)) {
            $io->error('Usage: app:ai-scan:fake-run <project-id> <user-email>');

            return Command::INVALID;
        }

        $projectId = Uuid::fromString($project);
        $userId = $this->userRepository->get($email)->id;
        $scenario = is_string($input->getOption('scenario')) ? $input->getOption('scenario') : 'happy';
        $beats = max(0, (int) (is_string($input->getOption('beats')) ? $input->getOption('beats') : '3'));
        $interval = max(0, (int) (is_string($input->getOption('beat-interval')) ? $input->getOption('beat-interval') : '0'));
        $render = $input->getOption('no-render') !== true;
        $image = is_string($input->getOption('image')) ? $input->getOption('image') : null;

        $this->storage->ensureBucket();

        // The scanner says hello: without a recent call it counts as absent
        // and uploads are refused.
        $tick = $this->scanner('POST', 'tick', ['scannerVersion' => self::SCANNER_VERSION, 'provider' => ['state' => 'ok']]);

        if ($tick['status'] !== 200 || ($tick['json']['mode'] ?? null) !== 'on') {
            $io->error(sprintf(
                'The scanner API answered %d, mode "%s". Switch AI scan on first: bin/console app:ai-scan:status --mode=on',
                $tick['status'],
                is_string($tick['json']['mode'] ?? null) ? $tick['json']['mode'] : '?',
            ));

            return Command::FAILURE;
        }

        try {
            foreach ($scenario === 'all' ? ['happy', 'stop', 'fail', 'refuse', 'cancel', 'accept', 'expire'] : [$scenario] as $name) {
                $io->section('Scenario: ' . $name);
                $scan = $this->upload($projectId, $userId, $image, $name);
                $this->say($io, $scan, 'uploaded');

                match ($name) {
                    'happy' => $this->happy($io, $scan, $projectId, $beats, $interval, $render),
                    'stop' => $this->stop($io, $scan, $userId, $projectId, $beats, $interval, $render),
                    'fail' => $this->fail($io, $scan, $userId, $projectId, $beats, $interval, $render),
                    'refuse' => $this->refuse($io, $scan, $beats, $interval),
                    'cancel' => $this->cancel($io, $scan, $userId),
                    'accept' => $this->accept($io, $scan, $projectId, $beats, $interval, $render),
                    'expire' => $this->expire($io, $scan, $userId, $projectId, $beats, $interval, $render),
                    'hang' => $this->hang($io, $scan, $beats, max(1, $interval)),
                    default => throw new \InvalidArgumentException('Unknown scenario: ' . $name),
                };
            }
        } catch (AiScanRefused $refused) {
            $io->error(sprintf('Refused: %s %s', $refused->refusal->value, json_encode($refused->params)));

            return Command::FAILURE;
        } catch (\RuntimeException | \InvalidArgumentException $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $io->success('Done.');

        return Command::SUCCESS;
    }

    // ---- scenarios ---------------------------------------------------------

    private function happy(SymfonyStyle $io, UuidInterface $scan, UuidInterface $projectId, int $beats, int $interval, bool $render): void
    {
        $this->intake($io, $scan, 'passed');
        $this->build($io, $scan, $projectId, $render ? 'proposed' : 'failed', $beats, $interval, $render);
    }

    private function stop(SymfonyStyle $io, UuidInterface $scan, UuidInterface $userId, UuidInterface $projectId, int $beats, int $interval, bool $render): void
    {
        $this->intake($io, $scan, 'stopped');
        $this->dispatch(new ContinueAiScan($scan, $userId));
        $this->say($io, $scan, '"Zkusit přesto"');
        $this->build($io, $scan, $projectId, $render ? 'proposed' : 'failed', $beats, $interval, $render);
    }

    private function fail(SymfonyStyle $io, UuidInterface $scan, UuidInterface $userId, UuidInterface $projectId, int $beats, int $interval, bool $render): void
    {
        $this->intake($io, $scan, 'passed');
        $this->build($io, $scan, $projectId, 'failed', $beats, $interval, false);
        $this->dispatch(new RetryAiScan($scan, $userId));
        $this->say($io, $scan, '"Zkusit znovu"');
        $this->build($io, $scan, $projectId, $render ? 'proposed' : 'failed', $beats, $interval, $render);
    }

    private function refuse(SymfonyStyle $io, UuidInterface $scan, int $beats, int $interval): void
    {
        $this->intake($io, $scan, 'passed');
        $job = $this->claim($scan, 'build');
        $this->beats($io, $scan, $job, $beats, $interval);
        $this->call($scan, $job, 'finish', ['outcome' => 'refused', 'usage' => $this->usage(1)]);
        $this->say($io, $scan, 'finish(refused)');
    }

    private function cancel(SymfonyStyle $io, UuidInterface $scan, UuidInterface $userId): void
    {
        $this->dispatch(new CancelAiScan($scan, $userId));
        $this->say($io, $scan, 'cancelled while checking (refunded)');
        $this->dispatch(new DiscardAiScan($scan, $userId));
        $this->say($io, $scan, 'discarded');
    }

    private function accept(SymfonyStyle $io, UuidInterface $scan, UuidInterface $projectId, int $beats, int $interval, bool $render): void
    {
        $this->happy($io, $scan, $projectId, $beats, $interval, $render);
        // The transition only: creating the template is AcceptTemplateScan's (P3).
        $this->transitions->accept($scan->toString(), null, null);
        $this->say($io, $scan, 'accept (state only — no template is created by the fake run)');
    }

    private function expire(SymfonyStyle $io, UuidInterface $scan, UuidInterface $userId, UuidInterface $projectId, int $beats, int $interval, bool $render): void
    {
        $this->happy($io, $scan, $projectId, $beats, $interval, $render);
        $this->connection->executeStatement("UPDATE ai_scan SET expires_at = '2000-01-01 00:00:00' WHERE id = :id", ['id' => $scan->toString()]);
        $result = $this->purge->run();
        $this->say($io, $scan, sprintf('retention forced into the past, purge ran (expired %d)', $result['expired']));
    }

    private function hang(SymfonyStyle $io, UuidInterface $scan, int $beats, int $interval): void
    {
        $this->intake($io, $scan, 'passed');
        $job = $this->claim($scan, 'build');
        $this->beats($io, $scan, $job, max(1, $beats), $interval);
        $io->warning('The fake scanner now stops answering. Kill this command, or wait: the lease runs out 120 s after the last heartbeat and the reaper (status read, claim, tick or `app:ai-scan:status --reap`) restarts the scan and closes this run\'s usage row.');
        sleep(3600);
    }

    // ---- the two sides -----------------------------------------------------

    private function upload(UuidInterface $projectId, UuidInterface $userId, null|string $image, string $label): UuidInterface
    {
        $path = $image ?? $this->generatedPicture($label);

        try {
            $id = $this->dispatch(new RequestAiScan(
                scanId: $this->provideIdentity->next(),
                requestId: 'fake-' . bin2hex(random_bytes(8)),
                projectId: $projectId,
                userId: $userId,
                kind: AiScanKind::Template,
                channel: AiScanChannel::Web,
                filePath: $path,
                originalName: basename($path),
                options: [],
                consentGiven: true,
            ));
        } finally {
            if ($image === null) {
                @unlink($path);
            }
        }

        if (!$id instanceof UuidInterface) {
            throw new \RuntimeException('The upload returned no scan id.');
        }

        return $id;
    }

    private function intake(SymfonyStyle $io, UuidInterface $scan, string $outcome): void
    {
        $job = $this->claim($scan, 'intake');
        $source = $this->scanner('GET', sprintf('%s/source?run=%d', $scan->toString(), $job['run']), null, $job['leaseToken']);

        if ($source['status'] !== 200) {
            throw new \RuntimeException(sprintf('source answered %d', $source['status']));
        }

        // A real intake normalises here. The fake one hands the original
        // bytes back as "working copy" and "preview" — the app never decodes.
        foreach (['working', 'preview'] as $which) {
            $this->scanner('PUT', sprintf('%s/artifact/%s?run=%d', $scan->toString(), $which, $job['run']), $source['raw'], $job['leaseToken']);
        }

        $this->call($scan, $job, 'beat', ['seq' => ++$this->seq, 'stage' => 'check', 'usage' => $this->usage(1, 'pre-check-model')]);

        $this->call($scan, $job, 'finish', $outcome === 'passed'
            ? ['outcome' => 'passed', 'preCheck' => ['verdict' => 'ok', 'confidence' => 0.97], 'usage' => $this->usage(1, 'pre-check-model')]
            : ['outcome' => 'stopped', 'reason' => 'photo_of_print', 'preCheck' => ['verdict' => 'photo_of_print'], 'usage' => $this->usage(1, 'pre-check-model')]);

        $this->say($io, $scan, 'intake finish(' . $outcome . ')');
    }

    private function build(SymfonyStyle $io, UuidInterface $scan, UuidInterface $projectId, string $outcome, int $beats, int $interval, bool $render): void
    {
        $job = $this->claim($scan, 'build');
        $this->say($io, $scan, 'build claimed, run ' . $job['run']);
        $this->beats($io, $scan, $job, $beats, $interval);

        $candidate = null;

        if ($render) {
            $answer = $this->call($scan, $job, 'render', ['seq' => ++$this->seq, 'design' => $this->design($projectId)]);
            $candidate = is_int($answer['candidate'] ?? null) ? $answer['candidate'] : null;
            $io->writeln(sprintf('    render → candidate %s, %d issue(s)', $candidate ?? 'none', is_array($answer['issues'] ?? null) ? count($answer['issues']) : 0));
        }

        if ($outcome === 'proposed' && $candidate !== null) {
            $this->call($scan, $job, 'finish', [
                'outcome' => 'proposed',
                'candidate' => $candidate,
                'report' => $this->report($candidate),
                'boxes' => ['elements' => ['headline' => ['x' => 80, 'y' => 120, 'w' => 740, 'h' => 90, 'lineWidth' => 612.5, 'colour' => '#111111']]],
                'usage' => $this->usage($beats + 1),
            ]);
        } else {
            $this->call($scan, $job, 'finish', ['outcome' => 'failed', 'error' => 'build', 'usage' => $this->usage($beats + 1)]);
        }

        $this->say($io, $scan, 'finish');
    }

    /**
     * @param array{run: int, leaseToken: string} $job
     */
    private function beats(SymfonyStyle $io, UuidInterface $scan, array $job, int $count, int $interval): void
    {
        $stages = ['read', 'analyse', 'build', 'refine'];

        for ($i = 0; $i < $count; $i++) {
            $answer = $this->call($scan, $job, 'beat', [
                'seq' => ++$this->seq,
                'stage' => $stages[min($i, count($stages) - 1)],
                'note' => $i === 1 ? 'texts_found' : null,
                'noteParams' => ['n' => 7],
                'usage' => $this->usage($i + 1),
                'resources' => ['rssMb' => 310 + $i, 'cpuSeconds' => 1.5 * ($i + 1)],
            ]);

            $io->writeln(sprintf('    beat %d → cancel %s', $i + 1, json_encode($answer['cancel'] ?? null)));

            if (($answer['cancel'] ?? false) === true) {
                throw new \RuntimeException('The app told the scanner to stop (the scan was cancelled or interrupted).');
            }

            if ($interval > 0) {
                sleep($interval);
            }
        }
    }

    /**
     * @return array{run: int, leaseToken: string}
     */
    private function claim(UuidInterface $scan, string $lane): array
    {
        $answer = $this->scanner('POST', 'claim', ['lane' => $lane, 'scannerVersion' => self::SCANNER_VERSION, 'instruments' => $this->definition->requiredInstruments(), 'capacity' => 1]);
        $job = $answer['json']['job'] ?? null;

        if (!is_array($job)) {
            throw new \RuntimeException(sprintf(
                'No %s job to claim (mode %s, reason %s).',
                $lane,
                is_string($answer['json']['mode'] ?? null) ? $answer['json']['mode'] : '?',
                is_string($answer['json']['reason'] ?? null) ? $answer['json']['reason'] : '—',
            ));
        }

        $claimed = ['run' => is_int($job['run'] ?? null) ? $job['run'] : 0, 'leaseToken' => is_string($job['leaseToken'] ?? null) ? $job['leaseToken'] : ''];

        if (($job['id'] ?? null) !== $scan->toString()) {
            // Somebody else's scan was first in the queue: give it back.
            $this->scanner('POST', sprintf('%s/release', is_string($job['id'] ?? null) ? $job['id'] : ''), ['run' => $claimed['run'], 'usage' => []], $claimed['leaseToken']);

            throw new \RuntimeException('Another scan is ahead in the queue — the fake run only drives its own. Try again when the queue is empty.');
        }

        return $claimed;
    }

    /**
     * @param array{run: int, leaseToken: string} $job
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function call(UuidInterface $scan, array $job, string $endpoint, array $body): array
    {
        $answer = $this->scanner('POST', sprintf('%s/%s', $scan->toString(), $endpoint), ['run' => $job['run'], ...$body], $job['leaseToken']);

        if ($answer['status'] !== 200 || ($answer['json']['ok'] ?? false) !== true) {
            throw new \RuntimeException(sprintf('%s answered %d %s', $endpoint, $answer['status'], json_encode($answer['json'])));
        }

        return $answer['json'];
    }

    /**
     * One call to the scanner API, in-process: a real request through the
     * kernel — firewall, authenticator and controller included.
     *
     * @param null|array<string, mixed>|string $body JSON (array) or raw bytes (string)
     * @return array{status: int, json: array<string, mixed>, raw: string}
     */
    private function scanner(string $method, string $path, null|array|string $body = null, null|string $leaseToken = null): array
    {
        $tokens = array_values(array_filter(array_map(trim(...), explode(',', $this->scannerToken))));
        $networks = array_values(array_filter(array_map(trim(...), explode(',', $this->scanNetworkCidr))));

        $server = [
            // An address inside the configured scan network: its first entry's base address.
            'REMOTE_ADDR' => in_array('127.0.0.1/32', $networks, true) ? '127.0.0.1' : explode('/', $networks[0] ?? '127.0.0.1')[0],
            'HTTP_AUTHORIZATION' => 'Bearer ' . ($tokens[0] ?? ''),
            'HTTP_X_SCANNER_PROTOCOL' => '1',
            'CONTENT_TYPE' => is_string($body) ? 'application/octet-stream' : 'application/json',
        ];

        if ($leaseToken !== null) {
            $server['HTTP_X_SCAN_LEASE'] = $leaseToken;
        }

        $request = Request::create(
            '/internal/scanner/' . $path,
            $method,
            server: $server,
            content: is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body,
        );

        $response = $this->httpKernel->handle($request);
        $raw = (string) $response->getContent();
        $json = json_decode($raw, true);

        /** @var array<string, mixed> $decoded */
        $decoded = is_array($json) ? $json : [];

        return ['status' => $response->getStatusCode(), 'json' => $decoded, 'raw' => $raw];
    }

    private function dispatch(object $message): mixed
    {
        try {
            return $this->bus->dispatch($message)->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $failed) {
            throw $failed->getPrevious() instanceof AiScanRefused ? $failed->getPrevious() : $failed;
        }
    }

    private function say(SymfonyStyle $io, UuidInterface $scan, string $what): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT s.status, s.error_code, s.try, s.run,
                    (SELECT COALESCE(SUM(delta), 0) FROM ai_credit_ledger l WHERE l.scan_id = s.id) AS credit,
                    (SELECT COUNT(*) FROM ai_usage_event u WHERE u.scan_id = s.id AND u.closed_at IS NULL) AS open_usage
               FROM ai_scan s WHERE s.id = :id',
            ['id' => $scan->toString()],
        );

        if ($row === false) {
            $io->writeln(sprintf('  %s — %s: the row is gone', $scan->toString(), $what));

            return;
        }

        $io->writeln(sprintf(
            '  %s — %s → <info>%s</info>%s · try %s · run %s · credit %s · open usage rows %s',
            $scan->toString(),
            $what,
            is_string($row['status']) ? $row['status'] : '?',
            is_string($row['error_code']) ? ' (' . $row['error_code'] . ')' : '',
            is_scalar($row['try']) ? (string) $row['try'] : '?',
            is_scalar($row['run']) ? (string) $row['run'] : '?',
            is_scalar($row['credit']) ? (string) $row['credit'] : '?',
            is_scalar($row['open_usage']) ? (string) $row['open_usage'] : '?',
        ));
    }

    // ---- fixtures ----------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function usage(int $step, string $model = 'fake-model'): array
    {
        return [
            'provider' => 'fake',
            'model' => $model,
            'inputTokens' => 1200 * $step,
            'outputTokens' => 300 * $step,
            'cacheReadTokens' => 800 * $step,
            'cacheWriteTokens' => 100,
            'engineCostMicroUsd' => 40_000 * $step,
            'turns' => $step,
            'durationMs' => 1500 * $step,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function design(UuidInterface $projectId): array
    {
        $family = null;

        foreach ($this->getFonts->allForProject($projectId) as $font) {
            foreach ($font->faces as $face) {
                $family ??= $font->faceFamily($face);
            }
        }

        if ($family === null) {
            throw new \RuntimeException('The project has no font — a template scan cannot start there.');
        }

        return [
            'canvas' => ['width' => 900, 'height' => 1200],
            'elements' => [
                ['kind' => 'background', 'id' => 'bg', 'asset' => AiScanStandIn::ASSET_ID],
                ['kind' => 'text', 'id' => 'headline', 'text' => 'Fake scan', 'font' => $family, 'size' => 72, 'x' => 80, 'y' => 120, 'width' => 740],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function report(int $candidate): array
    {
        return [
            'status' => 'proposed',
            'candidate' => $candidate,
            'headline' => 'headline',
            'unfinished' => false,
            'stopReason' => null,
            'counts' => ['texts' => 1, 'shapes' => 0, 'places' => 1, 'background' => true],
            'placeholders' => [['id' => 'bg', 'name' => 'Pozadí', 'role' => 'background', 'fillable' => true, 'box' => [0, 0, 900, 1200]]],
            'fonts' => [[
                'group' => 'headline', 'role' => 'headline', 'texts' => ['headline'], 'pattern' => 'nearest_available',
                'face' => 'Fake (Regular)', 'closeness' => 0.62, 'mismatch' => 0.38, 'sizeChangePercent' => -4,
                'sourceClass' => 'sans', 'sourceLooksLike' => 'Futura PT', 'guessSupported' => false,
            ]],
            'simplifications' => [],
            'approximations' => [],
            'omitted' => [],
            'lowConfidenceTexts' => [],
            'shrunk' => [],
            'paletteSnaps' => [],
            'notes' => [],
        ];
    }

    private function generatedPicture(string $label): string
    {
        $path = sprintf('%s/ai-scan-fake-%s.png', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        $image = imagecreatetruecolor(900, 1200);

        if ($image === false) {
            throw new \RuntimeException('GD could not create the picture.');
        }

        // Different bytes per scenario and per run: an identical picture
        // would share one `source_sha256` and trip the "failed twice" rule.
        imagefill($image, 0, 0, (int) imagecolorallocate($image, random_int(40, 220), random_int(40, 220), random_int(40, 220)));
        imagestring($image, 5, 40, 40, 'AI scan fake run: ' . $label, (int) imagecolorallocate($image, 255, 255, 255));
        imagepng($image, $path);

        return $path;
    }
}
