<?php

declare(strict_types=1);

namespace WBoost\Web\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use WBoost\Web\Message\AiScan\RequestAiScan;
use WBoost\Web\Message\AiScan\SetAiScanRuntime;
use WBoost\Web\Message\AiScan\SetProjectAiScan;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Services\AiScan\AiScanDefinition;
use WBoost\Web\Services\AiScan\AiScanRuntimeState;
use WBoost\Web\Services\AiScan\AiScanStandIn;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\AiScan\AiScanChannel;
use WBoost\Web\Value\AiScan\AiScanKind;
use WBoost\Web\Value\AiScan\AiScanMode;
use WBoost\Web\Value\AiScan\AiScanRefused;

/**
 * Shared set-up for the AI scan tests (use it in a `KernelTestCase` /
 * `WebTestCase`): switch the feature on, start a scan the way the upload
 * does, and talk to `/internal/scanner/*` as the scanner — from the scan
 * subnet of `.env.test`, with its token.
 *
 * Everything reads the CURRENT test container (`self::getContainer()`), so it
 * keeps working across the kernel reboots a `KernelBrowser` does between
 * requests.
 */
trait AiScanTesting
{
    public const string SCAN_IP = '10.99.0.5';
    public const string TOKEN = 'test-scanner-token-0123456789abcdef';
    public const string PREVIOUS_TOKEN = 'test-previous-token-0123456789abcdef';
    public const string FACE = 'Rubik (Rubik Regular)';

    /**
     * Mode `on`, customers allowed, a scanner seen just now.
     */
    public static function switchOn(AiScanMode $mode = AiScanMode::On, bool $adminOnly = false, bool $scannerSeen = true): void
    {
        self::bus()->dispatch(new SetAiScanRuntime(mode: $mode, adminOnly: $adminOnly));

        if ($scannerSeen) {
            self::getContainer()->get(AiScanRuntimeState::class)->scannerSeen('test-1');
        }
    }

    public static function entitle(string $projectId, bool $enabled = true, null|int $monthlyLimit = 10): void
    {
        self::bus()->dispatch(new SetProjectAiScan(
            Uuid::fromString($projectId),
            $enabled,
            $monthlyLimit,
            Uuid::fromString(TestDataFixture::ADMIN_USER_ID),
        ));
    }

    /** Records the current consent version on a user (what ticking the dialog does). */
    public static function consent(string $email): void
    {
        $definition = self::getContainer()->get(AiScanDefinition::class);

        self::connection()->executeStatement(
            'UPDATE "user" SET ai_scan_consent_version = :version, ai_scan_consent_at = NOW() WHERE email = :email',
            ['version' => $definition->consentVersion(), 'email' => $email],
        );
    }

    /**
     * A PNG the header checks accept. Random colour: every picture has its
     * own sha256 unless `$seed` pins it.
     */
    public static function picture(int $width = 900, int $height = 1200, null|int $seed = null): string
    {
        $path = sprintf('%s/ai-scan-test-%s.png', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));
        assert($image !== false);
        $seed ??= random_int(0, 0xFFFFFF);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, ($seed >> 16) & 255, ($seed >> 8) & 255, $seed & 255));
        imagepng($image, $path);

        return $path;
    }

    /**
     * Starts a scan exactly like the upload controller: through
     * `RequestAiScan`. Throws the refusal the handler threw.
     *
     * @throws AiScanRefused
     *
     * @phpstan-impure
     */
    public static function start(
        string $projectId = TestDataFixture::PROJECT_1_ID,
        string $email = TestDataFixture::USER_1_EMAIL,
        null|string $picture = null,
        bool $consent = true,
        null|string $requestId = null,
        AiScanKind $kind = AiScanKind::Template,
    ): UuidInterface {
        $users = self::getContainer()->get(UserRepository::class);
        $path = $picture ?? self::picture();

        try {
            $id = self::bus()->dispatch(new RequestAiScan(
                scanId: Uuid::uuid7(),
                requestId: $requestId ?? bin2hex(random_bytes(8)),
                projectId: Uuid::fromString($projectId),
                userId: $users->get($email)->id,
                kind: $kind,
                channel: AiScanChannel::Web,
                filePath: $path,
                originalName: 'design.png',
                options: [],
                consentGiven: $consent,
            ))->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $failed) {
            throw $failed->getPrevious() ?? $failed;
        } finally {
            if ($picture === null) {
                @unlink($path);
            }
        }

        assert($id instanceof UuidInterface);

        return $id;
    }

    /**
     * One scanner call.
     *
     * @param null|array<string, mixed>|string $body JSON or raw bytes
     * @param array<string, string> $server overrides (peer address, token, protocol)
     * @return array{status: int, json: array<string, mixed>, raw: string}
     *
     * @phpstan-impure
     */
    public static function scanner(KernelBrowser $client, string $method, string $path, null|array|string $body = null, null|string $lease = null, array $server = []): array
    {
        $defaults = [
            'REMOTE_ADDR' => self::SCAN_IP,
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN,
            'HTTP_X_SCANNER_PROTOCOL' => '1',
            'CONTENT_TYPE' => is_string($body) ? 'application/octet-stream' : 'application/json',
        ];

        if ($lease !== null) {
            $defaults['HTTP_X_SCAN_LEASE'] = $lease;
        }

        $client->request(
            $method,
            '/internal/scanner/' . $path,
            server: array_filter([...$defaults, ...$server], static fn (string $value): bool => $value !== ''),
            content: is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body,
        );

        $raw = (string) $client->getResponse()->getContent();
        $json = json_decode($raw, true);

        /** @var array<string, mixed> $decoded */
        $decoded = is_array($json) ? $json : [];

        return ['status' => $client->getResponse()->getStatusCode(), 'json' => $decoded, 'raw' => $raw];
    }

    /**
     * Claims a job of the lane; returns `{id, run, leaseToken, job}`.
     *
     * @return array{id: string, run: int, lease: string, job: array<string, mixed>}
     *
     * @phpstan-impure
     */
    public static function claim(KernelBrowser $client, string $lane): array
    {
        $answer = self::scanner($client, 'POST', 'claim', ['lane' => $lane, 'scannerVersion' => 'test-1', 'instruments' => self::instruments(), 'capacity' => 4]);
        $job = $answer['json']['job'] ?? null;

        if (!is_array($job)) {
            throw new \LogicException('No job to claim: ' . $answer['raw']);
        }

        /** @var array<string, mixed> $job */
        return [
            'id' => is_string($job['id'] ?? null) ? $job['id'] : '',
            'run' => is_int($job['run'] ?? null) ? $job['run'] : -1,
            'lease' => is_string($job['leaseToken'] ?? null) ? $job['leaseToken'] : '',
            'job' => $job,
        ];
    }

    /**
     * What a scanner that is not "too old" announces: every instrument the
     * definition requires.
     *
     * @return list<string>
     */
    public static function instruments(): array
    {
        return self::getContainer()->get(AiScanDefinition::class)->requiredInstruments();
    }

    /**
     * The intake's part: claim, store the two artifacts, finish `passed`.
     */
    public static function passIntake(KernelBrowser $client, string $scanId): void
    {
        $intake = self::claim($client, 'intake');

        if ($intake['id'] !== $scanId) {
            throw new \LogicException('The intake claimed another scan.');
        }

        self::passIntakeArtifacts($client, $scanId, $intake);
    }

    /**
     * @param array{id: string, run: int, lease: string, job: array<string, mixed>} $intake
     */
    public static function passIntakeArtifacts(KernelBrowser $client, string $scanId, array $intake): void
    {
        foreach (['working', 'preview'] as $which) {
            self::scanner($client, 'PUT', sprintf('%s/artifact/%s?run=%d', $scanId, $which, $intake['run']), 'WEBP-BYTES-' . $which, $intake['lease']);
        }

        $done = self::scanner($client, 'POST', $scanId . '/finish', ['run' => $intake['run'], 'outcome' => 'passed', 'usage' => self::usage(1)], $intake['lease']);

        if (($done['json']['status'] ?? null) !== 'queued') {
            throw new \LogicException('The intake did not pass: ' . $done['raw']);
        }
    }

    /**
     * Upload → intake passed → build claimed.
     *
     * @return array{id: string, run: int, lease: string, job: array<string, mixed>}
     *
     * @phpstan-impure
     */
    public static function running(KernelBrowser $client, string $projectId = TestDataFixture::PROJECT_1_ID, string $email = TestDataFixture::USER_1_EMAIL): array
    {
        $scanId = self::start($projectId, $email)->toString();
        self::passIntake($client, $scanId);

        return self::claim($client, 'build');
    }

    /**
     * @return array<string, mixed>
     */
    public static function usage(int $step, string $model = 'test-model'): array
    {
        return [
            'provider' => 'anthropic',
            'model' => $model,
            'inputTokens' => 1000 * $step,
            'outputTokens' => 200 * $step,
            'cacheReadTokens' => 500 * $step,
            'cacheWriteTokens' => 50,
            'engineCostMicroUsd' => 100_000 * $step,
            'turns' => $step,
            'durationMs' => 2000 * $step,
        ];
    }

    /**
     * A design the preflight accepts in the fixture project (Rubik).
     *
     * @return array<string, mixed>
     */
    public static function design(string $text = 'Headline', string $font = self::FACE): array
    {
        return [
            'canvas' => ['width' => 900, 'height' => 1200],
            'elements' => [
                ['kind' => 'background', 'id' => 'bg', 'asset' => AiScanStandIn::ASSET_ID],
                ['kind' => 'text', 'id' => 'headline', 'text' => $text, 'font' => $font, 'size' => 72, 'x' => 80, 'y' => 120, 'width' => 740],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function report(int $candidate): array
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
                'face' => self::FACE, 'closeness' => 0.62, 'mismatch' => 0.38, 'sizeChangePercent' => -4,
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

    /**
     * A design with everything the review can change: a background photo, a
     * headline, a small text, a logo, a photo and a shape.
     *
     * @return array<string, mixed>
     */
    public static function richDesign(): array
    {
        return [
            'canvas' => ['width' => 900, 'height' => 1200],
            'elements' => [
                ['kind' => 'background', 'id' => 'bg', 'asset' => AiScanStandIn::ASSET_ID, 'fillable' => true],
                ['kind' => 'shape', 'id' => 'pruh', 'shape' => 'rectangle', 'fill' => '#c8102e', 'x' => 0, 'y' => 1000, 'width' => 900, 'height' => 200],
                ['kind' => 'image', 'id' => 'foto', 'asset' => AiScanStandIn::ASSET_ID, 'x' => 100, 'y' => 400, 'width' => 400, 'height' => 300, 'input' => ['name' => 'Fotografie']],
                ['kind' => 'image', 'id' => 'logo', 'asset' => AiScanStandIn::ASSET_ID, 'x' => 700, 'y' => 60, 'width' => 140, 'height' => 60, 'input' => ['name' => 'Logo']],
                ['kind' => 'text', 'id' => 'headline', 'text' => 'Headline', 'font' => self::FACE, 'size' => 72, 'color' => '#c8102e', 'x' => 80, 'y' => 120, 'width' => 600],
                ['kind' => 'text', 'id' => 'sub', 'text' => 'Small print', 'font' => self::FACE, 'size' => 36, 'x' => 80, 'y' => 240, 'width' => 600],
            ],
        ];
    }

    /**
     * The report the scanner would write for {@see richDesign()}: one font
     * to decide, one measured match, a doubtful text, a left-out thing, a
     * shrunk text, a snapped colour, a simplification.
     *
     * @return array<string, mixed>
     */
    public static function richReport(int $candidate): array
    {
        return [
            'counts' => ['texts' => 2, 'shapes' => 1, 'places' => 3, 'background' => true],
            'placeholders' => [
                ['id' => 'bg', 'name' => 'Pozadí', 'role' => 'background', 'fillable' => true, 'box' => [0, 0, 900, 1200]],
                ['id' => 'logo', 'name' => 'Logo', 'role' => 'logo', 'fillable' => true, 'box' => [700, 60, 140, 60]],
                ['id' => 'foto', 'name' => 'Fotografie', 'role' => 'photo', 'fillable' => true, 'box' => [100, 400, 400, 300]],
            ],
            'fonts' => [
                [
                    'group' => 'headline', 'role' => 'headline', 'texts' => ['headline'], 'pattern' => 'nearest_available',
                    'face' => self::FACE, 'closeness' => 0.62, 'mismatch' => 0.38, 'sizeChangePercent' => -4,
                    'sourceClass' => 'sans', 'sourceLooksLike' => 'Futura PT', 'guessSupported' => false,
                ],
                [
                    'group' => 'body', 'role' => 'body', 'texts' => ['sub'], 'pattern' => 'measured_match',
                    'face' => self::FACE, 'closeness' => 0.93, 'mismatch' => 0.07, 'sizeChangePercent' => 0,
                    'sourceClass' => 'sans', 'sourceLooksLike' => null, 'guessSupported' => false,
                ],
            ],
            'simplifications' => [['code' => 'shadow_omitted', 'element' => 'headline', 'box' => [80, 120, 600, 90], 'n' => 1]],
            'omitted' => [['code' => 'picture', 'box' => [600, 800, 200, 100], 'diff' => 0.2, 'by' => 'model']],
            'lowConfidenceTexts' => [['id' => 'sub', 'reason' => 'small', 'box' => [80, 240, 300, 40], 'by' => 'model']],
            'shrunk' => [['id' => 'sub']],
            'paletteSnaps' => [['from' => '#c9112f', 'to' => '#c8102e']],
        ] + self::report($candidate);
    }

    /**
     * What the scanner measured on the kept candidate of {@see richDesign()}.
     *
     * @return list<array<string, mixed>>
     */
    public static function richBoxes(): array
    {
        return [
            ['id' => 'bg', 'kind' => 'background', 'box' => [0, 0, 900, 1200]],
            ['id' => 'pruh', 'kind' => 'shape', 'box' => [0, 1000, 900, 200]],
            ['id' => 'foto', 'kind' => 'image', 'box' => [100, 400, 400, 300]],
            ['id' => 'logo', 'kind' => 'image', 'box' => [700, 60, 140, 60]],
            ['id' => 'headline', 'kind' => 'text', 'box' => [80, 120, 600, 90], 'colour' => '#c8102e', 'lines' => 1, 'sourceLineWidths' => [420.0], 'fittedSize' => 72],
            ['id' => 'sub', 'kind' => 'text', 'box' => [80, 240, 300, 40], 'colour' => '#000000', 'lines' => 1, 'sourceLineWidths' => [210.0], 'fittedSize' => 44],
        ];
    }

    /**
     * Upload → intake → build → one candidate → `ready`, with the rich
     * proposal (or the plain one of {@see design()} / {@see report()}).
     *
     * @phpstan-impure
     */
    public static function ready(
        KernelBrowser $client,
        string $projectId = TestDataFixture::PROJECT_1_ID,
        string $email = TestDataFixture::USER_1_EMAIL,
        bool $rich = true,
    ): string {
        $build = self::running($client, $projectId, $email);
        $scanId = $build['id'];

        $render = self::scanner($client, 'POST', $scanId . '/render', ['run' => $build['run'], 'seq' => 1, 'design' => $rich ? self::richDesign() : self::design()], $build['lease']);

        if (($render['json']['candidate'] ?? null) !== 1) {
            throw new \LogicException('The candidate was not rendered: ' . $render['raw']);
        }

        $finish = self::scanner($client, 'POST', $scanId . '/finish', [
            'run' => $build['run'], 'outcome' => 'proposed', 'candidate' => 1,
            'report' => $rich ? self::richReport(1) : self::report(1),
            'boxes' => $rich ? self::richBoxes() : [],
            'usage' => self::usage(2),
        ], $build['lease']);

        if (($finish['json']['status'] ?? null) !== 'ready') {
            throw new \LogicException('The scan did not become ready: ' . $finish['raw']);
        }

        return $scanId;
    }

    /**
     * @return array<string, mixed>
     *
     * @phpstan-impure
     */
    public static function row(string $scanId): array
    {
        $row = self::connection()->fetchAssociative('SELECT * FROM ai_scan WHERE id = :id', ['id' => $scanId]);
        assert(is_array($row));

        return $row;
    }

    /**
     * The scan's ledger lines as `try:reason:delta`, oldest first.
     *
     * @return list<string>
     *
     * @phpstan-impure
     */
    public static function ledger(string $scanId): array
    {
        /** @var list<string> $lines */
        $lines = self::connection()->fetchFirstColumn(
            "SELECT try || ':' || reason || ':' || delta FROM ai_credit_ledger WHERE scan_id = :id ORDER BY try, created_at, CASE reason WHEN 'reserve' THEN 0 ELSE 1 END",
            ['id' => $scanId],
        );

        return $lines;
    }

    /**
     * Usage rows as `run:step:outcome` (`open` while not closed).
     *
     * @return list<string>
     *
     * @phpstan-impure
     */
    public static function usageRows(string $scanId): array
    {
        /** @var list<string> $rows */
        $rows = self::connection()->fetchFirstColumn(
            "SELECT run || ':' || step || ':' || COALESCE(outcome, 'open') FROM ai_usage_event WHERE scan_id = :id ORDER BY step DESC, run",
            ['id' => $scanId],
        );

        return $rows;
    }

    public static function connection(): Connection
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    public static function bus(): MessageBusInterface
    {
        return self::getContainer()->get(MessageBusInterface::class);
    }

    /** @phpstan-impure */
    public static function rowCount(string $table, string $where = 'TRUE'): int
    {
        $count = self::connection()->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE %s', $table, $where));

        return is_numeric($count) ? (int) $count : -1;
    }

    /** @phpstan-impure */
    public static function runtimeValue(string $column): mixed
    {
        return self::connection()->fetchOne(sprintf('SELECT %s FROM ai_scan_runtime WHERE id = 1', $column));
    }

    /**
     * One column of a scan row as a string (JSON columns come back as text).
     *
     * @phpstan-impure
     */
    public static function text(string $scanId, string $column): string
    {
        $value = self::row($scanId)[$column] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /** @phpstan-impure */
    public static function number(string $scanId, string $column): int
    {
        $value = self::row($scanId)[$column] ?? null;

        return is_numeric($value) ? (int) $value : -1;
    }

    /**
     * Walks a decoded JSON answer: `self::dig($json, 'image', 'contentType')`.
     */
    public static function dig(mixed $data, int|string ...$path): mixed
    {
        foreach ($path as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }

            $data = $data[$key];
        }

        return $data;
    }

    /**
     * @return array<mixed>
     */
    public static function decoded(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : $json;

        return is_array($decoded) ? $decoded : [];
    }
}
