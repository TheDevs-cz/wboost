<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Internal;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Message\AiScan\RetryAiScan;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\AiScan\AiScanLimits;

/**
 * Rule 7's bound: a build the scanner hands back (`release`) goes to the
 * queue without consuming the automatic restart and with the queue timeout
 * started again — so the releases of a try are counted, and one more than
 * {@see AiScanLimits::MAX_BUILD_RELEASES} fails the scan as `interrupted`,
 * refunded. A scan whose every run ends in a release is not claimed (and
 * paid for) for ever.
 */
final class ScannerReleaseCapTest extends WebTestCase
{
    use AiScanTesting;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::entitle(TestDataFixture::PROJECT_1_ID, true, null);
        self::switchOn();
    }

    public function testAScanReleasedOnceTooOftenFailsAsInterruptedWithARefund(): void
    {
        $build = self::running($this->client);
        $id = $build['id'];

        for ($release = 1; $release <= AiScanLimits::MAX_BUILD_RELEASES; $release++) {
            $answer = self::scanner($this->client, 'POST', $id . '/release', ['run' => $build['run'], 'usage' => self::usage($release)], $build['lease']);
            self::assertSame([200, true, 'queued'], [$answer['status'], $answer['json']['applied'] ?? null, $answer['json']['status'] ?? null], 'release ' . $release);
            self::assertSame($release, self::number($id, 'releases'));
            self::assertSame(0, self::number($id, 'auto_restart'), 'a release consumes no automatic restart');
            self::assertSame(['1:reserve:-1'], self::ledger($id));

            // Claimed again — a new run, a new usage row.
            $build = self::claim($this->client, 'build');
            self::assertSame($id, $build['id']);
            self::assertSame($release + 1, $build['run']);
        }

        // One more: the scan does not go back.
        $answer = self::scanner($this->client, 'POST', $id . '/release', ['run' => $build['run'], 'usage' => self::usage(9)], $build['lease']);
        self::assertSame([200, true, 'failed'], [$answer['status'], $answer['json']['applied'] ?? null, $answer['json']['status'] ?? null]);

        $row = self::row($id);
        self::assertSame('failed', $row['status']);
        self::assertSame('interrupted', $row['error_code']);
        self::assertNull($row['lease_expires_at']);
        self::assertNull($row['waiting_since']);
        self::assertNotNull($row['expires_at']);
        self::assertSame(0, $row['failure_count'], 'not the picture\'s fault');
        self::assertSame(['1:reserve:-1', '1:refund:1'], self::ledger($id), 'refunded, once');
        self::assertSame(0, self::rowCount('ai_usage_event', sprintf("scan_id = '%s' AND closed_at IS NULL", $id)), 'every run\'s usage is closed');
        self::assertSame(
            AiScanLimits::MAX_BUILD_RELEASES + 1,
            self::rowCount('ai_usage_event', sprintf("scan_id = '%s' AND step = 'builder' AND outcome = 'released'", $id)),
        );
        // What the runs spent is recorded all the same.
        self::assertSame(1, self::rowCount('ai_usage_event', sprintf("scan_id = '%s' AND step = 'builder' AND run = %d AND cost_micro_usd = 900000", $id, $build['run'])));

        // Nothing is left to claim, and a repeated release changes nothing.
        $none = self::scanner($this->client, 'POST', 'claim', ['lane' => 'build', 'scannerVersion' => 'test-1', 'instruments' => self::instruments(), 'capacity' => 4]);
        self::assertNull($none['json']['job'] ?? null);
        self::scanner($this->client, 'POST', $id . '/release', ['run' => $build['run'], 'usage' => self::usage(9)], $build['lease']);
        self::assertSame(['1:reserve:-1', '1:refund:1'], self::ledger($id));

        // "Zkusit znovu" is a new try: it counts its own releases.
        self::bus()->dispatch(new RetryAiScan(Uuid::fromString($id), Uuid::fromString(TestDataFixture::USER_1_ID)));
        self::assertSame('queued', self::text($id, 'status'));
        self::assertSame(0, self::number($id, 'releases'));
    }
}
