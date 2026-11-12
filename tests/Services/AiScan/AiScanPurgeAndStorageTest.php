<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\AiScan;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Mcp\Design\CompilationContextFactory;
use WBoost\Web\Mcp\Design\Dsl\DslParser;
use WBoost\Web\Message\AiScan\DiscardAiScan;
use WBoost\Web\Message\Project\DeleteProject;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\AiScan\AiScanPurge;
use WBoost\Web\Services\AiScan\AiScanSpend;
use WBoost\Web\Services\AiScan\AiScanStandIn;
use WBoost\Web\Services\AiScan\AiScanRuntimeState;
use WBoost\Web\Services\AiScan\AiScanTransitions;
use WBoost\Web\Services\Font\FitTextToWidth;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\FileSource;

/**
 * Storage (private, by secret), the nightly purge, project deletion, the
 * stand-in, the spend ceilings and the metric fit.
 */
final class AiScanPurgeAndStorageTest extends WebTestCase
{
    use AiScanTesting;

    private const string PRIVATE_ROOT = '/tmp/wboost-test-private';
    private const string PUBLIC_ROOT = '/tmp/wboost-test-uploads';

    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    private static int $reads = 0;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::entitle(TestDataFixture::PROJECT_1_ID, true, null);
        self::switchOn();
    }

    public function testScanFilesLiveOnlyInThePrivateStoreAndNoUrlNamesThem(): void
    {
        $id = self::start()->toString();
        $directory = $this->directory($id);

        self::assertFileExists(self::PRIVATE_ROOT . '/' . $directory . '/original.png');
        self::assertFileDoesNotExist(self::PUBLIC_ROOT . '/' . $directory . '/original.png');

        // Two different buckets, and the public base URL is the PUBLIC one's.
        $container = self::getContainer();
        self::assertSame('wboost-private', $container->getParameter('env(S3_PRIVATE_BUCKET_NAME)'));
        $publicBucket = $_SERVER['S3_BUCKET_NAME'] ?? null;
        self::assertIsString($publicBucket);
        self::assertNotSame('wboost-private', $publicBucket);
        $publicUrl = $container->get(UploaderHelper::class)->getPublicPath($directory . '/original.png');
        self::assertStringNotContainsString('wboost-private', $publicUrl);
        self::assertStringEndsWith('/' . $publicBucket . '/' . $directory . '/original.png', $publicUrl, 'the public base addresses the public bucket only');

        // The secret is in no status document.
        self::assertStringNotContainsString(self::text($id, 'storage_secret'), (string) json_encode(self::getContainer()->get(\WBoost\Web\Services\AiScan\AiScans::class)->view(
            self::getContainer()->get(\WBoost\Web\Repository\AiScanRepository::class)->get(Uuid::fromString($id)),
        )));
    }

    public function testExpiryMovesEndedScansAndOnlyDropsTheFilesOfAcceptedAndDiscardedOnes(): void
    {
        $client = $this->client;
        $transitions = self::getContainer()->get(AiScanTransitions::class);

        $ready = $this->ready($client);
        $accepted = $this->ready($client);
        $transitions->accept($accepted, null, null);
        $notYet = $this->ready($client);
        $inProgress = self::start()->toString();

        self::connection()->executeStatement(
            "UPDATE ai_scan SET expires_at = NOW() - INTERVAL '1 hour' WHERE id IN (:a, :b)",
            ['a' => $ready, 'b' => $accepted],
        );

        $result = self::getContainer()->get(AiScanPurge::class)->run();

        self::assertSame(1, $result['expired']);
        self::assertSame(1, $result['filesDropped']);

        self::assertSame('expired', self::text($ready, 'status'));
        self::assertSame('', self::text($ready, 'proposal'));
        self::assertSame('', self::text($ready, 'expires_at'));
        self::assertDirectoryDoesNotExist(self::PRIVATE_ROOT . '/' . dirname($this->directory($ready)));

        self::assertSame('accepted', self::text($accepted, 'status'), 'keeps its state');
        self::assertNotSame('', self::text($accepted, 'proposal'));
        self::assertDirectoryDoesNotExist(self::PRIVATE_ROOT . '/' . dirname($this->directory($accepted)));

        self::assertSame('ready', self::text($notYet, 'status'));
        self::assertFileExists(self::PRIVATE_ROOT . '/' . $this->directory($notYet) . '/candidate-1.webp');
        self::assertSame('checking', self::text($inProgress, 'status'), 'a scan in progress never expires');

        // A second run finds nothing left to do.
        $again = self::getContainer()->get(AiScanPurge::class)->run();
        self::assertSame(0, $again['expired'] + $again['filesDropped']);
    }

    public function testAnExpiryThatLosesTheRaceToAnAcceptDeletesNothing(): void
    {
        $client = $this->client;
        $transitions = self::getContainer()->get(AiScanTransitions::class);
        $id = $this->ready($client);
        self::connection()->executeStatement("UPDATE ai_scan SET expires_at = NOW() - INTERVAL '1 hour' WHERE id = :id", ['id' => $id]);

        // The accept wins…
        self::assertTrue($transitions->accept($id, null, null));
        // …so the expiry's conditional update changes no row, and it is the
        // caller's rule that files go only after a successful expire.
        self::assertFalse($transitions->expire($id));
        self::assertSame('accepted', self::text($id, 'status'));
        self::assertFileExists(self::PRIVATE_ROOT . '/' . $this->directory($id) . '/candidate-1.webp');
    }

    public function testThePurgeDeletesOrphanDirectoriesOlderThanADayAndClosesLeftoverUsageRows(): void
    {
        $live = self::start()->toString();
        $root = self::PRIVATE_ROOT . '/ai-scan/' . TestDataFixture::PROJECT_1_ID;

        $oldOrphan = $root . '/0193a000-0000-7000-8000-00000000aaaa/secret';
        $newOrphan = $root . '/0193a000-0000-7000-8000-00000000bbbb/secret';

        foreach ([$oldOrphan, $newOrphan] as $directory) {
            mkdir($directory, 0777, true);
            file_put_contents($directory . '/original.png', 'x');
        }

        touch($oldOrphan . '/original.png', time() - 90000);
        // The live scan's file is old too — but its row exists and is not expired.
        touch(self::PRIVATE_ROOT . '/' . $this->directory($live) . '/original.png', time() - 90000);

        // A usage row left open by a scan that is gone.
        self::connection()->executeStatement(
            "INSERT INTO ai_usage_event (id, scan_id, run, step, occurred_at, project_id, project_name, owner_email, user_email, channel, kind)
             VALUES (gen_random_uuid(), gen_random_uuid(), 1, 'builder', NOW(), :p, 'P', 'o@test.cz', 'u@test.cz', 'web', 'template')",
            ['p' => TestDataFixture::PROJECT_1_ID],
        );

        $result = self::getContainer()->get(AiScanPurge::class)->run();

        self::assertSame(1, $result['orphanDirectories']);
        self::assertSame(1, $result['usageClosed']);
        self::assertDirectoryDoesNotExist(dirname($oldOrphan));
        self::assertFileExists($newOrphan . '/original.png', 'younger than 24 hours: an upload may still be in flight');
        self::assertFileExists(self::PRIVATE_ROOT . '/' . $this->directory($live) . '/original.png');
        self::assertSame(1, self::rowCount('ai_usage_event', "outcome = 'purged' AND closed_at IS NOT NULL"));

        (new \Symfony\Component\Filesystem\Filesystem())->remove(dirname($newOrphan));
    }

    public function testDiscardDeletesTheFilesAtOnceAndKeepsTheRow(): void
    {
        $client = $this->client;
        $id = $this->ready($client);
        $directory = self::PRIVATE_ROOT . '/' . dirname($this->directory($id));
        self::assertDirectoryExists($directory);

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new DiscardAiScan(Uuid::fromString($id), Uuid::fromString(TestDataFixture::USER_1_ID)));

        self::assertSame('discarded', self::text($id, 'status'));
        self::assertDirectoryDoesNotExist($directory);
        self::assertSame(['1:reserve:-1', '1:settle:0'], self::ledger($id), 'a delivered scan stays counted');
    }

    public function testDeletingAProjectRemovesItsScansAndTheirFilesButNotTheUsage(): void
    {
        $client = $this->client;
        $id = $this->ready($client);
        $projectDirectory = self::PRIVATE_ROOT . '/ai-scan/' . TestDataFixture::PROJECT_1_ID;
        self::assertDirectoryExists($projectDirectory);

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new DeleteProject(Uuid::fromString(TestDataFixture::PROJECT_1_ID)));

        self::assertSame(0, self::rowCount('ai_scan'));
        self::assertDirectoryDoesNotExist($projectDirectory);
        self::assertSame(2, self::rowCount('ai_usage_event', sprintf("scan_id = '%s'", $id)), 'usage is FK-less and stays');
        self::assertSame(2, self::rowCount('ai_credit_ledger', sprintf("scan_id = '%s'", $id)));
    }

    public function testTheStandInIsNotAGalleryPictureAndNeverEntersTheBin(): void
    {
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $project = $container->get(ProjectRepository::class)->get(Uuid::fromString(TestDataFixture::PROJECT_1_ID));
        $files = $container->get(FileUploadRepository::class);

        $before = $files->countByProjectAndSource($project->id, FileSource::ProjectImage);
        $trashed = $files->countTrashed($project->id, FileSource::ProjectImage);

        $standIn = new FileUpload(Uuid::uuid7(), $project, new \DateTimeImmutable(), FileSource::ScanStandIn, 'file-upload/stand-in.png', null, 'stand-in.png');
        $entityManager->persist($standIn);
        $entityManager->flush();

        // Hidden from every gallery listing…
        self::assertSame($before, $files->countByProjectAndSource($project->id, FileSource::ProjectImage));
        self::assertNotContains($standIn, $files->listByProjectAndSource($project->id, FileSource::ProjectImage));

        // …and it cannot be moved to the bin, so the purge can never take it.
        $standIn->moveToTrash(new \DateTimeImmutable());
        $entityManager->flush();

        self::assertFalse($standIn->isTrashed());
        self::assertSame($trashed, $files->countTrashed($project->id, FileSource::ProjectImage));
        self::assertSame(0, $files->countTrashed($project->id, FileSource::ScanStandIn));
        self::assertNotContains($standIn, $files->listTrashedBefore(new \DateTimeImmutable('+1 year')));
        self::assertFalse(FileSource::ScanStandIn->isGalleryPicture());

        // A real gallery picture still goes to the bin.
        $picture = new FileUpload(Uuid::uuid7(), $project, new \DateTimeImmutable(), FileSource::ProjectImage, 'file-upload/x.png');
        $picture->moveToTrash(new \DateTimeImmutable());
        self::assertTrue($picture->isTrashed());
    }

    public function testTheReservedStandInIdResolvesOnlyWhereTheScanRenderPassesIt(): void
    {
        $container = self::getContainer();
        $project = $container->get(ProjectRepository::class)->get(Uuid::fromString(TestDataFixture::PROJECT_1_ID));
        $factory = $container->get(CompilationContextFactory::class);
        $document = DslParser::parse([
            'canvas' => ['width' => 900, 'height' => 1200],
            'elements' => [['kind' => 'background', 'id' => 'bg', 'asset' => AiScanStandIn::ASSET_ID]],
        ]);

        // Every existing caller: the id names nothing.
        self::assertSame([], $factory->forProject($project, $document)->assets);

        // The scan render path: one bundled picture, written to storage on first use.
        $assets = $container->get(AiScanStandIn::class)->assets();
        $context = $factory->forProject($project, $document, $assets);

        self::assertSame([AiScanStandIn::ASSET_ID], array_keys($context->assets));
        self::assertSame(AiScanStandIn::PATH, $context->assets[AiScanStandIn::ASSET_ID]->path);
        self::assertFileExists(self::PUBLIC_ROOT . '/' . AiScanStandIn::PATH);
        $size = getimagesize(self::PUBLIC_ROOT . '/' . AiScanStandIn::PATH);
        self::assertIsArray($size);
        self::assertSame([AiScanStandIn::WIDTH, AiScanStandIn::HEIGHT], [$size[0], $size[1]]);
    }

    public function testTheCeilingsCountRecordedSpendPlusTheReservedBudgetOfRunningRuns(): void
    {
        $client = $this->client;
        $spend = self::getContainer()->get(AiScanSpend::class);
        $now = new \DateTimeImmutable();
        $total = static fn (): int => self::fresh($spend->totalToday($now));
        $project = static fn (string $id): int => self::fresh($spend->projectToday($id, $now));
        $allows = static fn (string $id): bool => self::fresh($spend->allowsAnotherRun(self::getContainer()->get(AiScanRuntimeState::class)->get(), $id, $now));

        self::assertSame(0, $total());

        $build = self::running($client);
        // Intake recorded $0.10; one run in flight reserves $1.50.
        self::assertSame(100_000 + 1_500_000, $total());
        self::assertSame(100_000 + 1_500_000, $project(TestDataFixture::PROJECT_1_ID));
        self::assertSame(0, $project(TestDataFixture::PROJECT_2_ID));
        self::assertSame(1_500_000, $spend->reservedNow());

        self::scanner($client, 'POST', $build['id'] . '/beat', ['run' => 1, 'seq' => 1, 'usage' => self::usage(4)], $build['lease']);
        self::assertSame(100_000 + 400_000 + 1_500_000, $total());

        self::scanner($client, 'POST', $build['id'] . '/finish', ['run' => 1, 'outcome' => 'refused', 'usage' => self::usage(5)], $build['lease']);
        // The run ended: its reservation is gone, what it spent stays — refund or not.
        self::assertSame(100_000 + 500_000, $total());

        $runtime = self::getContainer()->get(AiScanRuntimeState::class)->get();
        self::assertTrue($allows(TestDataFixture::PROJECT_1_ID));

        // $5 a project: 0.60 spent + 1.50 for the next run fits; with 3.60 spent it does not.
        self::connection()->executeStatement('UPDATE ai_usage_event SET cost_micro_usd = 3600000 WHERE scan_id = :id AND step = :step', ['id' => $build['id'], 'step' => 'builder']);
        self::assertFalse($allows(TestDataFixture::PROJECT_1_ID));
        self::assertTrue($allows(TestDataFixture::PROJECT_2_ID), 'each project has its own ceiling');
        self::assertFalse($spend->projectCeilingReached($runtime, TestDataFixture::PROJECT_1_ID, $now));

        // Yesterday's spend is not today's.
        self::connection()->executeStatement("UPDATE ai_usage_event SET occurred_at = occurred_at - INTERVAL '2 days'");
        self::assertSame(0, $total());
    }

    public function testTheMetricFitFindsTheSizeAtWhichALineIsAsWideAsInTheSource(): void
    {
        $fonts = self::PUBLIC_ROOT . '/fixtures/fonts';
        @mkdir($fonts, 0777, true);
        copy(__DIR__ . '/../../Fixtures/fonts/Inter-ExtraBold-StaticSubset.ttf', $fonts . '/rubik-regular.ttf');

        $fit = self::getContainer()->get(FitTextToWidth::class);
        $project = Uuid::fromString(TestDataFixture::PROJECT_1_ID);

        $unit = $fit->advanceAtOnePixel($project, self::FACE, 'Hello');
        self::assertNotNull($unit);
        self::assertGreaterThan(1.5, $unit);
        self::assertLessThan(4.0, $unit);

        // size = sourceLineWidth ÷ advanceWidth(face, text, 1 px): exact by construction…
        self::assertEqualsWithDelta(72.0, $fit->fit($project, self::FACE, 'Hello', 72 * $unit), 0.0001);
        // …linear in the width, and wider text needs a smaller size for the same width.
        self::assertEqualsWithDelta(36.0, $fit->fit($project, self::FACE, 'Hello', 36 * $unit), 0.0001);
        self::assertLessThan(72.0, (float) $fit->fit($project, self::FACE, 'Hello Hello', 72 * $unit));
        // Letter-spacing widens the line: the fitted size drops.
        self::assertLessThan(72.0, (float) $fit->fit($project, self::FACE, 'Hello', 72 * $unit, 100.0));
        // Against the face's own table: "ll" is two "l" advances.
        self::assertEqualsWithDelta(2 * (float) $fit->advanceAtOnePixel($project, self::FACE, 'l'), (float) $fit->advanceAtOnePixel($project, self::FACE, 'll'), 0.000001);

        self::assertNull($fit->fit($project, 'Unknown (Regular)', 'Hello', 100.0));
        self::assertNull($fit->fit($project, self::FACE, '', 100.0));
        self::assertNull($fit->fit($project, self::FACE, 'Hello', 0.0));

        unlink($fonts . '/rubik-regular.ttf');
    }

    /**
     * Every call is a new read of the database — says so to static analysis.
     *
     * @template T
     * @param T $value
     * @return T
     *
     * @phpstan-impure
     */
    private static function fresh(mixed $value): mixed
    {
        self::$reads++;

        return $value;
    }

    private function directory(string $scanId): string
    {
        return sprintf('ai-scan/%s/%s/%s', TestDataFixture::PROJECT_1_ID, $scanId, self::text($scanId, 'storage_secret'));
    }

    private function ready(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): string
    {
        $build = self::running($client);
        self::scanner($client, 'POST', $build['id'] . '/render', ['run' => 1, 'seq' => 1, 'design' => self::design()], $build['lease']);
        self::scanner($client, 'POST', $build['id'] . '/finish', ['run' => 1, 'outcome' => 'proposed', 'candidate' => 1, 'report' => self::report(1), 'usage' => self::usage(1)], $build['lease']);

        return $build['id'];
    }
}
