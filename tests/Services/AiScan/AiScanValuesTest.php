<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\AiScan;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\AiScan\AiScanDefinition;
use WBoost\Web\Services\AiScan\AiScanStandIn;
use WBoost\Web\Services\AiScan\AiScanTime;
use WBoost\Web\Services\AiScan\AiScanUpload;
use WBoost\Web\Value\AiScan\AiScanErrorCode;
use WBoost\Web\Value\AiScan\AiScanLimits;
use WBoost\Web\Value\AiScan\AiScanNote;
use WBoost\Web\Value\AiScan\AiScanRefusal;
use WBoost\Web\Value\AiScan\AiScanRefused;
use WBoost\Web\Value\AiScan\AiUsageFigures;
use WBoost\Web\Value\AiScan\ScannerApiCode;

/**
 * The small pure parts: months and days of the limits, the definition the
 * scanner receives, the header sniffing, the clamped usage figures, codes.
 */
final class AiScanValuesTest extends TestCase
{
    public function testTheReservesMonthIsPragueTimeAcrossMonthAndYearBoundaries(): void
    {
        $utc = new DateTimeZone('UTC');

        // 23:30 UTC on the last of October is already November in Prague (CET, +1).
        self::assertSame('2026-11', AiScanTime::period(new DateTimeImmutable('2026-10-31 23:30:00', $utc)));
        self::assertSame('2026-10', AiScanTime::period(new DateTimeImmutable('2026-10-31 22:59:59', $utc)));
        // In summer Prague is +2: 22:30 UTC on 30 June is 1 July there.
        self::assertSame('2026-07', AiScanTime::period(new DateTimeImmutable('2026-06-30 22:30:00', $utc)));
        self::assertSame('2026-06', AiScanTime::period(new DateTimeImmutable('2026-06-30 21:59:59', $utc)));
        self::assertSame('2027-01', AiScanTime::period(new DateTimeImmutable('2026-12-31 23:00:00', $utc)));
    }

    public function testADayOfTheLimitsStartsAtPragueMidnightAlsoOnTheDstDays(): void
    {
        $utc = new DateTimeZone('UTC');

        // 25 October 2026: clocks go back; the day is 25 hours long.
        $start = AiScanTime::startOfDay(new DateTimeImmutable('2026-10-25 12:00:00', $utc));
        self::assertSame('2026-10-24 22:00:00', $start->setTimezone($utc)->format('Y-m-d H:i:s'));
        $next = AiScanTime::startOfDay(new DateTimeImmutable('2026-10-26 12:00:00', $utc));
        self::assertSame(25 * 3600, $next->getTimestamp() - $start->getTimestamp());

        // 29 March 2026: clocks go forward; 23 hours.
        $spring = AiScanTime::startOfDay(new DateTimeImmutable('2026-03-29 12:00:00', $utc));
        self::assertSame('2026-03-28 23:00:00', $spring->setTimezone($utc)->format('Y-m-d H:i:s'));

        // Just after midnight UTC is still "today" in Prague only from 22:00 / 23:00 UTC of the day before.
        self::assertSame('2026-11-12 23:00:00', AiScanTime::startOfDay(new DateTimeImmutable('2026-11-12 23:30:00', $utc))->setTimezone($utc)->format('Y-m-d H:i:s'));
    }

    public function testSqlTimestampsRoundTrip(): void
    {
        $at = new DateTimeImmutable('2026-11-12 10:11:12', new DateTimeZone('Europe/Prague'));
        $sql = AiScanTime::sql($at);

        self::assertSame($at->getTimestamp(), AiScanTime::fromSql($sql)?->getTimestamp());
        self::assertNull(AiScanTime::fromSql(null));
        self::assertNull(AiScanTime::fromSql(''));
    }

    public function testTheDefinitionCarriesTheSameNumbersAsTheApp(): void
    {
        $definition = new AiScanDefinition(__DIR__ . '/../../../resources/ai-scan/definition');
        /** @var array{timing: array<string, int>, caps: array<string, int|float>, preCheck: array<string, mixed>, engine: array<string, mixed>} $json */
        $json = $definition->definition();

        self::assertSame(AiScanLimits::HEARTBEAT_SECONDS, $json['timing']['heartbeatSeconds']);
        self::assertSame(AiScanLimits::LEASE_SECONDS, $json['timing']['leaseSeconds']);
        self::assertSame(AiScanLimits::INTAKE_LEASE_SECONDS, $json['timing']['intakeLeaseSeconds']);
        self::assertSame(AiScanLimits::RUN_CAP_SECONDS, $json['timing']['runCapSeconds']);
        self::assertSame(105, $json['timing']['selfAbortSeconds']);
        self::assertSame(10, $json['timing']['heartbeatAttemptSeconds']);
        self::assertSame(20, $json['timing']['normaliseSeconds']);
        self::assertSame(20, $json['timing']['releaseAttemptSeconds']);
        self::assertSame(5, $json['timing']['killGraceSeconds']);
        self::assertSame(AiScanStandIn::ASSET_ID, $definition->definition()['standInAssetId'] ?? null);
        self::assertSame(AiScanLimits::TEMPLATE_MAX_LONG_EDGE, $definition->definition()['templateMaxLongEdgePx'] ?? null);
        // The pre-check's own request settings: 18 s, one retry after 2 s, 38 s in all (the keys the scanner reads).
        self::assertSame(
            ['requestTimeoutSeconds' => 18, 'retries' => 1, 'retryDelaysSeconds' => [2], 'maxSeconds' => 38],
            array_intersect_key($json['preCheck'], ['requestTimeoutSeconds' => 1, 'retries' => 1, 'retryDelaysSeconds' => 1, 'maxSeconds' => 1]),
        );
        // One name per number: a second key nobody reads would drift unnoticed.
        self::assertArrayNotHasKey('preCheckRequest', $json);
        self::assertArrayNotHasKey('beatAttemptSeconds', $json['timing']);
        self::assertArrayNotHasKey('shutdownReleaseSeconds', $json['timing']);
        self::assertSame([2, 8, 20, 30], $json['engine']['retryDelaysSeconds']);

        // Every outer bound exceeds what it wraps (architecture.md §4.9).
        $t = $json['timing'];
        self::assertLessThan($t['heartbeatSeconds'], $t['heartbeatAttemptSeconds']);
        self::assertLessThan($t['selfAbortSeconds'], 2 * $t['heartbeatSeconds'] + $t['appOutageToleranceSeconds'] + $t['heartbeatAttemptSeconds']);
        self::assertLessThan($t['leaseSeconds'], $t['selfAbortSeconds']);
        self::assertSame($t['intakeCapSeconds'] + $t['appCallAttemptSeconds'], $t['intakeLeaseSeconds']);
        self::assertLessThan($t['runCapSeconds'], $t['builderCapSeconds']);
        self::assertLessThan(AiScanLimits::SCANNER_ABSENT_SECONDS, $t['tickSeconds']);
        // The engine is dead before the app gives the lease up; the shutdown fits its limit.
        self::assertLessThan($t['leaseSeconds'], $t['selfAbortSeconds'] + $t['killGraceSeconds']);
        self::assertLessThanOrEqual($t['shutdownSeconds'], $t['killGraceSeconds'] + $t['releaseAttemptSeconds']);
        // Attempts of an app call start inside R, each ≤ one attempt: the last ends before the tool timeout.
        self::assertLessThan($t['appToolTimeoutSeconds'], $t['appOutageToleranceSeconds'] + $t['appCallAttemptSeconds']);
        self::assertLessThan($t['builderCapSeconds'], $t['appToolTimeoutSeconds']);
        // The pre-check ends inside the intake's work, the intake inside its cap.
        $preCheckSeconds = $json['preCheck']['maxSeconds'] ?? null;
        self::assertIsInt($preCheckSeconds);
        self::assertLessThan($t['intakeCapSeconds'] - $t['appOutageToleranceSeconds'], $preCheckSeconds + $t['normaliseSeconds']);

        self::assertSame(1_500_000, $definition->engineBudgetMicroUsd());
        self::assertSame(3, $definition->maxCandidates());
        self::assertSame(1, $definition->protocolVersion());
        self::assertNotSame('', $definition->consentVersion());
        self::assertMatchesRegularExpression('/^[0-9A-Za-z.]{1,16}-[0-9a-f]{12}$/', $definition->version());
        self::assertLessThanOrEqual(32, strlen($definition->version()), 'fits ai_scan.definition_version');

        foreach (['definition.json', 'finish.template.schema.json', 'system.common.md', 'system.template.md', 'precheck.md', 'tools.json'] as $file) {
            self::assertArrayHasKey($file, $definition->files());
        }
    }

    public function testTheAppAcceptsProtocolNAndNMinusOne(): void
    {
        $directory = sys_get_temp_dir() . '/ai-scan-definition-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory . '/definition.json', json_encode([
            'version' => '7',
            'protocolVersion' => 3,
            'prices' => ['version' => '2026-11', 'models' => ['m-1' => ['input' => 3, 'output' => 15, 'cacheRead' => 0.3, 'cacheWrite' => 3.75]]],
        ]));

        $definition = new AiScanDefinition($directory);

        self::assertTrue($definition->acceptsProtocol(3));
        self::assertTrue($definition->acceptsProtocol(2));
        self::assertFalse($definition->acceptsProtocol(1));
        self::assertFalse($definition->acceptsProtocol(4));
        self::assertFalse($definition->acceptsProtocol(0));

        // USD per million tokens × tokens = micro-USD; an unknown model has no price.
        self::assertSame(3_000_000 + 1_500_000 + 300_000 + 375_000, $definition->costMicroUsd('m-1', 1_000_000, 100_000, 1_000_000, 100_000));
        self::assertNull($definition->costMicroUsd('other', 1, 1, 1, 1));
        self::assertSame('2026-11', $definition->priceVersion());
        // A row without a 1-hour price charges 1-hour writes at twice its input price.
        self::assertSame(3_000_000 + 1_500_000 + 300_000 + 93_750 + 6 * 75_000, $definition->costMicroUsd('m-1', 1_000_000, 100_000, 1_000_000, 100_000, 75_000));

        unlink($directory . '/definition.json');
        rmdir($directory);
    }

    public function testTypesAreSniffedFromTheirFirstBytes(): void
    {
        self::assertSame('jpg', AiScanUpload::sniff("\xFF\xD8\xFF\xE0rest")['ext'] ?? null);
        self::assertSame('png', AiScanUpload::sniff("\x89PNG\r\n\x1A\nrest")['ext'] ?? null);
        self::assertSame('webp', AiScanUpload::sniff("RIFF\x00\x00\x00\x00WEBPVP8 ")['ext'] ?? null);
        self::assertSame('heic', AiScanUpload::sniff("\x00\x00\x00\x18ftypheic\x00\x00")['ext'] ?? null);
        self::assertSame('heic', AiScanUpload::sniff("\x00\x00\x00\x18ftypmif1\x00\x00")['ext'] ?? null);

        foreach (['<svg xmlns="http://www.w3.org/2000/svg"/>', '<?xml version="1.0"?><svg/>', '%PDF-1.7', 'GIF89a', "RIFF\x00\x00\x00\x00WAVE", "\x00\x00\x00\x18ftypisom", '', 'II*' . "\x00"] as $other) {
            self::assertNull(AiScanUpload::sniff($other));
        }
    }

    public function testAHeicIsAcceptedOnBytesAloneAndAMissingFileIsUnreadable(): void
    {
        $path = sys_get_temp_dir() . '/ai-scan-test-' . bin2hex(random_bytes(4)) . '.heic';
        file_put_contents($path, "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00" . random_bytes(200));

        $facts = AiScanUpload::inspect($path);
        unlink($path);

        self::assertSame('image/heic', $facts['contentType']);
        self::assertNull($facts['width']);
        self::assertNull($facts['height']);
        self::assertSame(64, strlen($facts['sha256']));

        try {
            AiScanUpload::inspect($path);
            self::fail('expected a refusal');
        } catch (AiScanRefused $refused) {
            self::assertSame(AiScanRefusal::UnsupportedImage, $refused->refusal);
            self::assertSame(['reason' => 'unreadable'], $refused->params);
        }
    }

    public function testThePriceTableChargesCacheWritesByLifetimeAtTheOfficialMultipliers(): void
    {
        $definition = new AiScanDefinition(dirname(__DIR__, 3) . '/resources/ai-scan/definition');
        $prices = $definition->definition()['prices'] ?? null;
        self::assertIsArray($prices);
        $models = $prices['models'] ?? null;
        self::assertIsArray($models);
        self::assertSame('2026-11-03', $definition->priceVersion());

        // platform.claude.com/docs/en/about-claude/pricing (read 2026-11-12): a 5-minute cache write is
        // 1.25 × the base input price, a 1-hour write 2 ×; a cache read 0.1 × — 0.05 × on Opus 5.5 and Sonnet 5.5.
        $readMultiplier = ['claude-opus-5-5' => 0.05, 'claude-sonnet-5-5' => 0.05, 'claude-haiku-5-5' => 0.1, 'claude-opus-4-8' => 0.1];
        self::assertEqualsCanonicalizing(array_keys($readMultiplier), array_keys($models));

        foreach ($models as $model => $price) {
            self::assertIsArray($price);
            self::assertIsString($model);
            $input = $price['input'] ?? null;
            self::assertIsNumeric($input);
            self::assertEqualsWithDelta(1.25 * $input, $price['cacheWrite'] ?? null, 1e-9, $model);
            self::assertEqualsWithDelta(2 * $input, $price['cacheWrite1h'] ?? null, 1e-9, $model);
            self::assertEqualsWithDelta($readMultiplier[$model] * $input, $price['cacheRead'] ?? null, 1e-9, $model);

            // One million tokens of each kind, priced apart.
            self::assertSame((int) round(1_000_000 * 1.25 * $input), $definition->costMicroUsd($model, 0, 0, 0, 1_000_000, 0));
            self::assertSame((int) round(1_000_000 * 2 * $input), $definition->costMicroUsd($model, 0, 0, 0, 1_000_000, 1_000_000));
            self::assertSame((int) round(250_000 * 1.25 * $input + 750_000 * 2 * $input), $definition->costMicroUsd($model, 0, 0, 0, 1_000_000, 750_000));
            // A 1-hour figure larger than the total cannot price more tokens than were written.
            self::assertSame((int) round(1_000_000 * 2 * $input), $definition->costMicroUsd($model, 0, 0, 0, 1_000_000, 5_000_000));
        }
    }

    public function testUsageFiguresReadTheCacheWriteSplit(): void
    {
        $figures = AiUsageFigures::fromPayload(['cacheWriteTokens' => 400, 'cacheWrite5mTokens' => 100, 'cacheWrite1hTokens' => 300]);
        self::assertSame([400, 300], [$figures->cacheWriteTokens, $figures->cacheWrite1hTokens]);

        // The total alone (an older scanner): all 5-minute.
        $figures = AiUsageFigures::fromPayload(['cacheWriteTokens' => 400]);
        self::assertSame([400, 0], [$figures->cacheWriteTokens, $figures->cacheWrite1hTokens]);

        // The split alone: the total is its sum; a 1-hour figure never exceeds the total.
        $figures = AiUsageFigures::fromPayload(['cacheWrite5mTokens' => 100, 'cacheWrite1hTokens' => 300]);
        self::assertSame([400, 300], [$figures->cacheWriteTokens, $figures->cacheWrite1hTokens]);
        $figures = AiUsageFigures::fromPayload(['cacheWriteTokens' => 500, 'cacheWrite1hTokens' => -3]);
        self::assertSame([500, 0], [$figures->cacheWriteTokens, $figures->cacheWrite1hTokens]);
    }

    public function testUsageFiguresAreClampedAndCodesOnly(): void
    {
        $figures = AiUsageFigures::fromPayload([
            'provider' => 'anthropic', 'model' => "model with spaces\nand a line", 'inputTokens' => -5, 'outputTokens' => '12',
            'cacheReadTokens' => 10.6, 'cacheWriteTokens' => PHP_INT_MAX, 'engineCostMicroUsd' => 250_000, 'turns' => 1_000_000, 'durationMs' => 99,
        ]);

        self::assertSame('', $figures->model);
        self::assertSame(0, $figures->inputTokens);
        self::assertSame(0, $figures->outputTokens, 'a string is not a count');
        self::assertSame(11, $figures->cacheReadTokens);
        self::assertSame(2_000_000_000, $figures->cacheWriteTokens);
        self::assertSame(100_000, $figures->turns);
        self::assertEquals(new AiUsageFigures(), AiUsageFigures::fromPayload('nonsense'));
    }

    public function testANoteCarriesOnlyItsNamedIntegers(): void
    {
        self::assertSame(['n' => 7], AiScanNote::TextsFound->params(['n' => 7, 'text' => 'free text']));
        self::assertSame(['n' => 0], AiScanNote::RowsFound->params(['n' => 'seven']));
        self::assertSame(['n' => 2, 'of' => 4], AiScanNote::ProviderRetry->params(['n' => 2, 'of' => 4]));
        self::assertSame([], AiScanNote::Zooming->params(['n' => 1]));
        self::assertSame(['n' => 100000], AiScanNote::TextsFound->params(['n' => PHP_INT_MAX]));
    }

    public function testWhichFailuresCountAgainstThePictureAndWhichAScannerMayReport(): void
    {
        $counted = array_values(array_filter(AiScanErrorCode::cases(), static fn (AiScanErrorCode $code): bool => $code->countsAgainstSource()));

        self::assertSame([AiScanErrorCode::Timeout, AiScanErrorCode::Build], $counted);
        self::assertNotContains(AiScanErrorCode::Interrupted, AiScanErrorCode::reportable());
        self::assertNotContains(AiScanErrorCode::QueueTimeout, AiScanErrorCode::reportable());
    }

    public function testRefusalsMapToAnswersNotErrors(): void
    {
        foreach (AiScanRefusal::cases() as $refusal) {
            self::assertContains($refusal->httpStatus(), [403, 409, 422, 429, 503], $refusal->value);
        }

        self::assertSame(409, ScannerApiCode::StaleRun->httpStatus());
        self::assertSame(409, ScannerApiCode::LeaseLost->httpStatus());
        self::assertSame(410, ScannerApiCode::Gone->httpStatus());

        foreach ([ScannerApiCode::ProtocolTooOld, ScannerApiCode::RendererBusy, ScannerApiCode::RendererUnavailable, ScannerApiCode::CandidateLimit, ScannerApiCode::MissingArtifact, ScannerApiCode::InvalidRequest, ScannerApiCode::UnsupportedKind] as $code) {
            self::assertSame(200, $code->httpStatus(), $code->value);
        }
    }
}
