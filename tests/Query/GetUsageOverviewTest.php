<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Entity\ExportEvent;
use WBoost\Web\Query\GetUsageOverview;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\ExportChannel;
use WBoost\Web\Value\ExportedTemplateType;

final class GetUsageOverviewTest extends KernelTestCase
{
    private const string OWNER_A_EMAIL = TestDataFixture::USER_1_EMAIL;
    private const string OWNER_B_EMAIL = TestDataFixture::USER_2_EMAIL;

    public function testAggregatesDistinctCountsPerOwnerProjectAndMonth(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $ownerA = Uuid::fromString(TestDataFixture::USER_1_ID);
        $ownerB = Uuid::fromString(TestDataFixture::USER_2_ID);
        $projectA = Uuid::fromString(TestDataFixture::PROJECT_1_ID);
        $projectB = Uuid::fromString(TestDataFixture::PROJECT_2_ID);

        $templateT1 = Uuid::uuid4();
        $templateT2 = Uuid::uuid4();
        $templateT4 = Uuid::uuid4();
        $variantV1 = Uuid::uuid4();
        $variantV3 = Uuid::uuid4();
        $variantV5 = Uuid::uuid4();

        // Owner A / Project A: T1·V1 exported twice in May (1 unique template,
        // 1 unique variant, 2 downloads), then T2·V3 once in June.
        $this->seed($entityManager, '2026-05-10', $ownerA, self::OWNER_A_EMAIL, $projectA, 'Projekt A', $templateT1, $variantV1);
        $this->seed($entityManager, '2026-05-12', $ownerA, self::OWNER_A_EMAIL, $projectA, 'Projekt A', $templateT1, $variantV1);
        $this->seed($entityManager, '2026-06-05', $ownerA, self::OWNER_A_EMAIL, $projectA, 'Projekt A', $templateT2, $variantV3);

        // Owner B / Project B: a single export in May.
        $this->seed($entityManager, '2026-05-20', $ownerB, self::OWNER_B_EMAIL, $projectB, 'Projekt B', $templateT4, $variantV5);

        $entityManager->flush();

        $overview = self::getContainer()->get(GetUsageOverview::class)->overview();

        self::assertSame(['2026-05', '2026-06'], $overview->months);
        self::assertSame(['5/2026', '6/2026'], $overview->chartCategories);

        // Grand totals: distinct templates T1,T2,T4 = 3; distinct variants V1,V3,V5 = 3; 4 downloads.
        self::assertSame(3, $overview->totalUniqueTemplates);
        self::assertSame(3, $overview->totalUniqueVariants);
        self::assertSame(4, $overview->totalDownloads);
        self::assertSame(2, $overview->clientCount);
        self::assertSame(2, $overview->projectCount);

        // Ordered by downloads desc → Owner A (3) first.
        $ownerARow = $overview->owners[0];
        self::assertSame(self::OWNER_A_EMAIL, $ownerARow->ownerEmail);
        self::assertSame(2, $ownerARow->uniqueTemplates);
        self::assertSame(2, $ownerARow->uniqueVariants);
        self::assertSame(3, $ownerARow->downloads);

        // Owner A has one project with the right monthly split.
        self::assertCount(1, $ownerARow->projects);
        $projectARow = $ownerARow->projects[0];
        self::assertSame('Projekt A', $projectARow->projectName);
        self::assertSame(1, $projectARow->templatesInMonth('2026-05'));
        self::assertSame(1, $projectARow->variantsInMonth('2026-05'));
        self::assertSame(2, $projectARow->downloadsInMonth('2026-05'));
        self::assertSame(1, $projectARow->templatesInMonth('2026-06'));
        self::assertSame(1, $projectARow->downloadsInMonth('2026-06'));

        $ownerBRow = $overview->owners[1];
        self::assertSame(self::OWNER_B_EMAIL, $ownerBRow->ownerEmail);
        self::assertSame(1, $ownerBRow->downloads);

        // Chart: one stacked series per client, unique templates per month.
        $seriesByName = [];
        foreach ($overview->chartSeries as $series) {
            $seriesByName[$series['name']] = $series['data'];
        }
        self::assertSame([1, 1], $seriesByName[self::OWNER_A_EMAIL] ?? null);
        self::assertSame([1, 0], $seriesByName[self::OWNER_B_EMAIL] ?? null);
    }

    public function testEmptyWhenNoExports(): void
    {
        $overview = self::getContainer()->get(GetUsageOverview::class)->overview();

        self::assertTrue($overview->isEmpty());
        self::assertSame([], $overview->months);
        self::assertSame(0, $overview->totalDownloads);
    }

    private function seed(
        EntityManagerInterface $entityManager,
        string $date,
        UuidInterface $ownerId,
        string $ownerEmail,
        UuidInterface $projectId,
        string $projectName,
        UuidInterface $templateId,
        UuidInterface $variantId,
    ): void {
        $entityManager->persist(new ExportEvent(
            Uuid::uuid7(),
            new DateTimeImmutable($date . ' 12:00:00'),
            ExportedTemplateType::SocialNetwork,
            ExportChannel::Web,
            $templateId,
            'Šablona',
            $variantId,
            $projectId,
            $projectName,
            $ownerId,
            $ownerEmail,
            null,
        ));
    }
}
