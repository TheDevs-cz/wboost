<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Calendar;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\Calendar\CalendarPdfProducer;
use WBoost\Web\Value\Calendar\CalendarPdfPlan;

/**
 * @covers \WBoost\Web\Services\Calendar\CalendarPdfProducer::chunks
 * @covers \WBoost\Web\Value\Calendar\CalendarPdfPlan
 */
final class CalendarPdfProducerTest extends TestCase
{
    /**
     * A year is never one HTML document: consecutive pages, a page cap, and a
     * byte cap on the photos a chunk carries.
     */
    public function testPagesAreChunkedByCountAndByTheWeightOfTheirPhotos(): void
    {
        $keys = ['cover', 'w01', 'w02', 'w03', 'w04', 'w05', 'w06', 'w07', 'back'];

        // No photos: the page cap alone.
        self::assertSame(
            [['cover', 'w01', 'w02', 'w03'], ['w04', 'w05', 'w06', 'w07'], ['back']],
            CalendarPdfProducer::chunks($keys, [], 4, 10_000_000),
        );

        // 3 MB photos, 8 MB per chunk: two photo pages a chunk — the third would cross the cap.
        $threeMb = array_fill_keys(['w01', 'w02', 'w03', 'w04', 'w05', 'w06', 'w07'], 3_000_000);
        self::assertSame(
            [['cover', 'w01', 'w02'], ['w03', 'w04'], ['w05', 'w06'], ['w07', 'back']],
            CalendarPdfProducer::chunks($keys, $threeMb, 6, 8_000_000),
        );

        // A page heavier than the cap is a chunk of its own — never dropped, never split.
        self::assertSame(
            [['cover'], ['w01'], ['w02', 'w03', 'w04', 'w05'], ['w06', 'w07', 'back']],
            CalendarPdfProducer::chunks($keys, ['w01' => 9_500_000, 'w02' => 100], 4, 8_000_000),
        );

        self::assertSame([], CalendarPdfProducer::chunks([], []));

        // Every page exactly once, in order — whatever the caps.
        foreach ([[1, 1], [6, 16_000_000], [100, 1]] as [$pages, $bytes]) {
            self::assertSame($keys, array_merge(...CalendarPdfProducer::chunks($keys, $threeMb, $pages, $bytes)));
        }
    }

    public function testMillimetresToPoints(): void
    {
        self::assertEqualsWithDelta(952.441, CalendarPdfPlan::pt(336.0), 0.001);
        self::assertEqualsWithDelta(72.0, CalendarPdfPlan::pt(25.4), 0.000001);
    }
}
