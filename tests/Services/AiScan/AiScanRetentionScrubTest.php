<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\AiScan;

use AsyncAws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToDeleteDirectory;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Message\AiScan\DiscardAiScan;
use WBoost\Web\Services\AiScan\AiScanNotifications;
use WBoost\Web\Services\AiScan\AiScanPurge;
use WBoost\Web\Services\AiScan\AiScanReaper;
use WBoost\Web\Services\AiScan\AiScanStorage;
use WBoost\Web\Services\AiScan\AiScanTransitions;
use WBoost\Web\Services\AiScan\RecordAiUsage;
use WBoost\Web\Services\AiScan\Template\ScanUploads;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * "Návrh i původní obrázek … potom smažeme" holds for the ROW too: what was
 * read from the picture (the proposal, its candidates, the fixes, the
 * report, the boxes of found text, the chosen pictures, a calendar's
 * snapshot, the file's name) leaves the row when the scan is discarded, when
 * it expires and when the retention of an accepted scan runs out — and the
 * pages that list or open such a scan still answer. And: a file delete that
 * fails is tried again the next night.
 */
final class AiScanRetentionScrubTest extends WebTestCase
{
    use AiScanTesting;

    private const string PRIVATE_ROOT = '/tmp/wboost-test-private';
    private const string PROJECT = TestDataFixture::PROJECT_1_ID;
    /** Every column the scrub empties, with what "empty" reads as. */
    private const array SCRUBBED = ['proposal' => '', 'fixes' => '', 'report' => '', 'text_boxes' => '', 'pictures' => '', 'restore' => '', 'candidates' => '[]'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::entitle(self::PROJECT, true, null);
        self::switchOn();
    }

    public function testADiscardedScanKeepsNothingReadFromThePictureAndItsPagesStillAnswer(): void
    {
        $id = $this->reviewed();
        self::assertStringContainsString('Headline', self::text($id, 'proposal'));
        self::assertStringContainsString('design.png', self::text($id, 'source_meta'));

        self::bus()->dispatch(new DiscardAiScan(Uuid::fromString($id), Uuid::fromString(TestDataFixture::USER_1_ID)));

        self::assertSame('discarded', self::text($id, 'status'));
        $this->assertScrubbed($id);
        self::assertDirectoryDoesNotExist(self::PRIVATE_ROOT . '/' . dirname($this->directory($id)));
        // What the lists and the admin read stays.
        self::assertStringContainsString('"discard"', self::text($id, 'events'));
        self::assertStringContainsString('image/png', self::text($id, 'source_meta'));

        $this->assertThePagesAnswer($id, 'discarded');
    }

    public function testAnExpiredScanKeepsNothingReadFromThePicture(): void
    {
        $id = $this->reviewed();
        $this->backdate($id);

        $result = self::getContainer()->get(AiScanPurge::class)->run();
        self::assertSame(1, $result['expired']);
        self::assertSame(0, $result['scrubbed'], 'the expiry itself took everything');

        self::assertSame('expired', self::text($id, 'status'));
        $this->assertScrubbed($id);
        self::assertSame('', self::text($id, 'expires_at'));

        $this->assertThePagesAnswer($id, 'expired');
    }

    public function testAnAcceptedScanKeepsItsReviewUntilTheRetentionRunsOutAndNothingAfter(): void
    {
        $id = $this->reviewed();
        self::assertTrue(self::getContainer()->get(AiScanTransitions::class)->accept($id, null, null));

        // Before the date: the purge leaves the read-only page its proposal.
        self::getContainer()->get(AiScanPurge::class)->run();
        self::assertStringContainsString('Headline', self::text($id, 'proposal'));
        self::assertNotSame('', self::text($id, 'report'));
        self::assertNotNull($this->proposal($id));

        $this->backdate($id);
        $result = self::getContainer()->get(AiScanPurge::class)->run();
        self::assertSame(1, $result['filesDropped']);

        self::assertSame('accepted', self::text($id, 'status'), 'keeps its state');
        $this->assertScrubbed($id);
        self::assertSame('', self::text($id, 'expires_at'));
        self::assertDirectoryDoesNotExist(self::PRIVATE_ROOT . '/' . dirname($this->directory($id)));

        $this->assertThePagesAnswer($id, 'accepted');

        $again = self::getContainer()->get(AiScanPurge::class)->run();
        self::assertSame(0, $again['filesDropped'] + $again['scrubbed']);
    }

    public function testAFileDeleteThatFailsIsTriedAgainTheNextNight(): void
    {
        $accepted = $this->reviewed();
        self::assertTrue(self::getContainer()->get(AiScanTransitions::class)->accept($accepted, null, null));
        $this->backdate($accepted);
        $directory = self::PRIVATE_ROOT . '/' . dirname($this->directory($accepted));

        // Tonight the storage refuses to delete.
        $result = $this->purgeWithBrokenStorage()->run();

        self::assertSame(0, $result['filesDropped']);
        self::assertDirectoryExists($directory);
        self::assertNotSame('', self::text($accepted, 'expires_at'), 'the row keeps its date — that is what the next run finds it by');
        $this->assertScrubbed($accepted);

        // Tomorrow it works: the files go, and only then the date.
        $result = self::getContainer()->get(AiScanPurge::class)->run();
        self::assertSame(1, $result['filesDropped']);
        self::assertDirectoryDoesNotExist($directory);
        self::assertSame('', self::text($accepted, 'expires_at'));
    }

    public function testTheSweepTakesFilesADiscardLeftBehindAndThePurgeScrubsRowsThatWereAlreadyPastThePoint(): void
    {
        // A scan discarded while the storage was down: its directory stayed.
        $leftBehind = $this->reviewed();
        $directory = self::PRIVATE_ROOT . '/' . dirname($this->directory($leftBehind));
        // A row that was discarded before the rule was complete still holds its texts.
        $old = $this->reviewed();
        $before = self::row($old);
        self::connection()->executeStatement("UPDATE ai_scan SET status = 'discarded' WHERE id IN (:a, :b)", ['a' => $leftBehind, 'b' => $old]);
        self::assertStringContainsString('Headline', self::text($old, 'proposal'));

        // Files younger than a day are never swept.
        self::assertSame(0, self::getContainer()->get(AiScanPurge::class)->run()['orphanDirectories']);
        self::assertDirectoryExists($directory);

        foreach ([$leftBehind, $old] as $id) {
            $this->age(self::PRIVATE_ROOT . '/' . dirname($this->directory($id)));
        }

        // (The first run already scrubbed both rows; put one back to count it.)
        self::connection()->executeStatement(
            'UPDATE ai_scan SET proposal = CAST(:proposal AS JSONB), report = CAST(:report AS JSONB), source_meta = CAST(:meta AS JSONB) WHERE id = :id',
            ['id' => $old, 'proposal' => $before['proposal'], 'report' => $before['report'], 'meta' => $before['source_meta']],
        );

        $result = self::getContainer()->get(AiScanPurge::class)->run();
        self::assertSame(2, $result['orphanDirectories']);
        self::assertSame(1, $result['scrubbed']);
        self::assertDirectoryDoesNotExist($directory);
        $this->assertScrubbed($old);
        $this->assertScrubbed($leftBehind);

        self::assertSame(0, self::getContainer()->get(AiScanPurge::class)->run()['scrubbed'], 'idempotent');
    }

    // ---- helpers -----------------------------------------------------------

    /** A `ready` scan with every column the scrub takes filled in. */
    private function reviewed(): string
    {
        $id = self::ready($this->client);

        self::connection()->executeStatement(
            "UPDATE ai_scan
                SET fixes = '{\"v\": 1, \"rev\": 1, \"name\": \"Tajný název\", \"items\": {}}',
                    pictures = '{\"places\": {}, \"uploads\": []}',
                    text_boxes = '{\"width\": 900, \"height\": 1200, \"boxes\": [[1, 2, 3, 4]]}',
                    restore = '{\"layoutJson\": \"{}\"}'
              WHERE id = :id",
            ['id' => $id],
        );

        foreach (array_keys(self::SCRUBBED) as $column) {
            self::assertNotContains(self::text($id, $column), ['', '[]'], $column . ' is filled before');
        }

        return $id;
    }

    private function assertScrubbed(string $id): void
    {
        foreach (self::SCRUBBED as $column => $empty) {
            self::assertSame($empty, self::text($id, $column), $column);
        }

        $meta = self::text($id, 'source_meta');
        self::assertStringNotContainsString('design.png', $meta, 'the file name is gone');
        self::assertStringNotContainsString('"name"', $meta);

        // Nothing of the design's text is left anywhere on the row.
        $row = (string) json_encode(self::row($id));
        self::assertStringNotContainsString('Headline', $row);
        self::assertStringNotContainsString('Tajný název', $row);
    }

    /** The history, the wizard's page, the status and the proposal document of a scrubbed scan. */
    private function assertThePagesAnswer(string $id, string $status): void
    {
        TestingLogin::logInAsUser($this->client, TestDataFixture::USER_1_EMAIL);

        $this->client->request('GET', sprintf('/project/%s/sablony/historie-skenu', self::PROJECT));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($id, (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', sprintf('/project/%s/sablony/z-obrazku/%s', self::PROJECT, $id));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/ai-scan/' . $id);
        self::assertResponseIsSuccessful();

        self::assertNull($this->proposal($id), 'no proposal is left to read');

        $this->client->request('GET', sprintf('/project/%s/ai-scans', self::PROJECT));
        self::assertResponseIsSuccessful();
        $list = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($list);
        self::assertIsArray($list['scans']);
        $rows = array_values(array_filter($list['scans'], static fn (mixed $row): bool => is_array($row) && ($row['id'] ?? null) === $id));
        self::assertCount(1, $rows);
        self::assertSame($status, $rows[0]['status']);
        self::assertNull($rows[0]['fileName']);
        self::assertFalse($rows[0]['hasProposal']);
        self::assertNotContains('open_proposal', (array) $rows[0]['actions']);

        // The admin's table reads the same row.
        TestingLogin::logInAsUser($this->client, TestDataFixture::ADMIN_USER_EMAIL);
        $this->client->request('GET', '/admin/usage?ai_scan=' . $id);
        self::assertResponseIsSuccessful();
    }

    private function proposal(string $id): mixed
    {
        TestingLogin::logInAsUser($this->client, TestDataFixture::USER_1_EMAIL);
        $this->client->request('GET', sprintf('/ai-scan/%s/proposal', $id));
        self::assertResponseIsSuccessful();
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($answer);

        return $answer['proposal'];
    }

    private function backdate(string $id): void
    {
        self::connection()->executeStatement("UPDATE ai_scan SET expires_at = NOW() - INTERVAL '1 hour' WHERE id = :id", ['id' => $id]);
    }

    private function directory(string $scanId): string
    {
        return sprintf('ai-scan/%s/%s/%s', self::PROJECT, $scanId, self::text($scanId, 'storage_secret'));
    }

    /** Makes every file under the directory two days old. */
    private function age(string $directory): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            assert($file instanceof \SplFileInfo);
            touch($file->getPathname(), time() - 2 * 86400);
        }
    }

    /** The purge as it is wired, over a storage whose deletes fail. */
    private function purgeWithBrokenStorage(): AiScanPurge
    {
        $container = self::getContainer();
        // The suite's private store (config/services_test.php), with a delete that fails.
        $broken = new Filesystem(new class(self::PRIVATE_ROOT) extends LocalFilesystemAdapter {
            public function deleteDirectory(string $prefix): void
            {
                throw UnableToDeleteDirectory::atLocation($prefix, 'the storage is down');
            }
        });

        return new AiScanPurge(
            $container->get(EntityManagerInterface::class)->getConnection(),
            $container->get(ClockInterface::class),
            $container->get(AiScanReaper::class),
            $container->get(AiScanTransitions::class),
            new AiScanStorage($broken, $container->get(S3Client::class), 'wboost-private'),
            $container->get(RecordAiUsage::class),
            $container->get(ScanUploads::class),
            $container->get(AiScanNotifications::class),
            $container->get(EntityManagerInterface::class),
        );
    }
}
