<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Query\GetExportVersions;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\ExportChannel;
use WBoost\Web\Value\ExportFillValues;
use WBoost\Web\Value\ExportHistory;

/**
 * The curated read model behind every history surface: pinned versions
 * first (most recently pinned on top), then the rest freshest first; the
 * dropdown's "recent" slice never repeats a pinned version.
 *
 * @covers \WBoost\Web\Query\GetExportVersions
 * @covers \WBoost\Web\Value\ExportHistory
 */
final class GetExportVersionsTest extends KernelTestCase
{
    public function testSplitsPinnedFromRecentAndOrdersBothSections(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $variant = $entityManager->find(TemplateVariant::class, TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);
        self::assertInstanceOf(TemplateVariant::class, $variant);

        // Eight distinct fills, one per day; #1 and #6 pinned (#1 pinned later).
        $versions = [];
        for ($i = 0; $i < 8; $i++) {
            $exportedAt = new DateTimeImmutable(sprintf('2026-09-%02d 10:00:00', $i + 1));
            $version = new TemplateExportVersion(
                Uuid::uuid7(),
                $variant->template,
                $variant,
                null,
                null,
                ExportChannel::Web,
                ExportFillValues::fromVariantWebForm([TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => "Fill $i"], [], []),
                "hash-$i",
                $exportedAt,
            );
            $entityManager->persist($version);
            $versions[$i] = $version;
        }
        $versions[6]->pin(new DateTimeImmutable('2026-09-10 08:00:00'));
        $versions[1]->pin(new DateTimeImmutable('2026-09-10 09:00:00'));
        $versions[1]->rename('Ta pravá');
        $entityManager->flush();
        $entityManager->clear();

        $history = self::getContainer()->get(GetExportVersions::class)->forVariant($variant->id);

        self::assertSame(8, $history->total());
        self::assertFalse($history->isEmpty());

        // Pinned: most recently pinned first, regardless of export date.
        self::assertSame(
            [$versions[1]->id->toString(), $versions[6]->id->toString()],
            array_map(static fn (TemplateExportVersion $v): string => $v->id->toString(), $history->pinned),
        );
        self::assertSame('Ta pravá', $history->pinned[0]->name);

        // Unpinned: freshest export first, pinned ones absent.
        self::assertSame(
            [7, 5, 4, 3, 2, 0],
            array_map(
                static fn (TemplateExportVersion $v): int => (int) substr($v->fillValues->texts[TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID], 5),
                $history->unpinned,
            ),
        );

        // The dropdown slice: MENU_RECENT of the unpinned, and there is more.
        self::assertCount(ExportHistory::MENU_RECENT, $history->recent());
        self::assertSame($versions[7]->id->toString(), $history->recent()[0]->id->toString());
        self::assertTrue($history->hasMoreThanRecent());
        self::assertFalse($history->hasMoreThanRecent(6));

        // The page order: pinned block, then the rest.
        $all = $history->all();
        self::assertCount(8, $all);
        self::assertSame($versions[1]->id->toString(), $all[0]->id->toString());
        self::assertSame($versions[6]->id->toString(), $all[1]->id->toString());
        self::assertSame($versions[7]->id->toString(), $all[2]->id->toString());

        // An untouched surface is simply empty.
        $empty = self::getContainer()->get(GetExportVersions::class)->forVariant(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_2_ID));
        self::assertTrue($empty->isEmpty());
        self::assertSame([], $empty->recent());
        self::assertSame(0, $empty->total());
    }
}
