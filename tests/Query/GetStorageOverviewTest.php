<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Query;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Query\GetStorageOverview;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\StorageCategory;

final class GetStorageOverviewTest extends KernelTestCase
{
    public function testAggregatesSizePerProjectAndRollsUpPerOwner(): void
    {
        // Owner 1 / Project 1: 300 B used + 700 B unreferenced.
        $this->seed(TestDataFixture::PROJECT_1_ID, TestDataFixture::USER_1_ID, TestDataFixture::USER_1_EMAIL, 300, false);
        $this->seed(TestDataFixture::PROJECT_1_ID, TestDataFixture::USER_1_ID, TestDataFixture::USER_1_EMAIL, 700, true);

        // Owner 2 / Project 2: 100 B, all in use.
        $this->seed(TestDataFixture::PROJECT_2_ID, TestDataFixture::USER_2_ID, TestDataFixture::USER_2_EMAIL, 100, false);

        // A leftover whose project is gone — must not be attributed to anyone.
        $this->seed(null, null, null, 50, true);

        $overview = self::getContainer()->get(GetStorageOverview::class)->overview();

        self::assertSame(4, $overview->totalFiles);
        self::assertSame(1150, $overview->totalSize);
        self::assertSame(2, $overview->orphanFiles);
        self::assertSame(750, $overview->orphanSize);

        // Reported as its own bucket rather than folded into a client's total.
        self::assertSame(1, $overview->unattributedFiles);
        self::assertSame(50, $overview->unattributedSize);
        self::assertSame(2, $overview->clientCount());

        // Ordered by size desc → owner 1 (1000 B) first.
        $ownerOne = $overview->owners[0];
        self::assertSame(TestDataFixture::USER_1_EMAIL, $ownerOne->ownerEmail);
        self::assertSame(1000, $ownerOne->totalSize);
        self::assertSame(700, $ownerOne->orphanSize);
        self::assertSame(2, $ownerOne->fileCount);
        self::assertSame(70.0, $ownerOne->orphanShare());

        self::assertCount(1, $ownerOne->projects);
        self::assertSame(TestDataFixture::PROJECT_1_ID, $ownerOne->projects[0]->projectId);
        self::assertSame(1000, $ownerOne->projects[0]->totalSize);

        $ownerTwo = $overview->owners[1];
        self::assertSame(TestDataFixture::USER_2_EMAIL, $ownerTwo->ownerEmail);
        self::assertSame(100, $ownerTwo->totalSize);
        self::assertSame(0, $ownerTwo->orphanSize);
        self::assertSame(0.0, $ownerTwo->orphanShare());
    }

    public function testChartSplitsEachClientIntoUsedAndUnreferencedMegabytes(): void
    {
        $this->seed(TestDataFixture::PROJECT_1_ID, TestDataFixture::USER_1_ID, TestDataFixture::USER_1_EMAIL, 2 * 1024 * 1024, false);
        $this->seed(TestDataFixture::PROJECT_1_ID, TestDataFixture::USER_1_ID, TestDataFixture::USER_1_EMAIL, 1024 * 1024, true);

        $overview = self::getContainer()->get(GetStorageOverview::class)->overview();

        self::assertSame([TestDataFixture::USER_1_EMAIL], $overview->chartLabels);
        self::assertSame(
            [
                ['name' => 'Používané', 'data' => [2.0]],
                ['name' => 'Nepoužívané', 'data' => [1.0]],
            ],
            $overview->chartSeries,
        );
    }

    public function testEmptyInventoryReportsZeroes(): void
    {
        $overview = self::getContainer()->get(GetStorageOverview::class)->overview();

        self::assertTrue($overview->isEmpty());
        self::assertSame(0, $overview->totalSize);
        self::assertSame([], $overview->owners);
        self::assertNull($overview->lastScannedAt);
        self::assertSame(0.0, $overview->orphanShare());
    }

    private function seed(
        null|string $projectId,
        null|string $ownerId,
        null|string $ownerEmail,
        int $size,
        bool $orphaned,
    ): void {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $entityManager->getConnection()->executeStatement(
            'INSERT INTO storage_object (
                id, path, size, last_modified_at, category, referenced_by, reference_count,
                project_id, project_name, owner_id, owner_email, orphaned, scanned_at, scan_id
             ) VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                Uuid::uuid7()->toString(),
                'fixtures/' . Uuid::uuid4()->toString() . '.png',
                $size,
                StorageCategory::GalleryImage->value,
                $orphaned ? null : 'file_upload.path',
                $orphaned ? 0 : 1,
                $projectId,
                $projectId === null ? null : 'Projekt ' . $projectId,
                $ownerId,
                $ownerEmail,
                $orphaned ? 'true' : 'false',
                '2026-08-03 10:00:00',
                Uuid::uuid7()->toString(),
            ],
        );
    }
}
