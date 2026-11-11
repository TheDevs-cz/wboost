<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\Calendar;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Exceptions\CalendarChangeForbidden;
use WBoost\Web\Exceptions\InvalidCalendarChange;
use WBoost\Web\Message\Calendar\SaveCalendar;
use WBoost\Web\MessageHandler\Calendar\SaveCalendarHandler;
use WBoost\Web\Repository\CalendarHistoryRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\Calendar\CalendarActor;
use WBoost\Web\Value\Calendar\CalendarContentOp;

/**
 * Item-wise merging and history of a save (docs/calendar/architecture.md
 * §C.5 "Merge semantics", handoff §10.8).
 *
 * @covers \WBoost\Web\MessageHandler\Calendar\SaveCalendarHandler
 */
final class SaveCalendarHandlerTest extends KernelTestCase
{
    private UuidInterface $calendarId;

    protected function setUp(): void
    {
        $this->calendarId = Uuid::fromString(TestDataFixture::CALENDAR_1_ID);
    }

    public function testTwoPeopleChangingDifferentItemsBothWin(): void
    {
        // The designer and the client each loaded the calendar, then each saved one thing.
        $this->save(CalendarActor::designer(), [new CalendarContentOp('events', 'designerEvent', self::event('Masopust'), '6. 2.')]);
        $this->save(CalendarActor::client(), [new CalendarContentOp('events', 'clientEvent01', self::event('Pouť'), '1. 6.')]);
        $this->save(CalendarActor::client(), [new CalendarContentOp('names', 'clientName001', ['date' => '2027-03-01', 'name' => 'Bedřich'], '1. 3.')]);

        self::assertEqualsCanonicalizing(['designerEvent', 'clientEvent01'], array_keys($this->items('events')));
        self::assertSame(['clientName001'], array_keys($this->items('names')));
        self::assertSame(3, $this->calendar()->revision);
        // The seeded waste kinds were never sent and are untouched.
        self::assertCount(4, $this->items('wasteKinds'));
    }

    public function testSameItemLastSaveWinsAndBothEntriesExist(): void
    {
        $this->save(CalendarActor::designer(), [new CalendarContentOp('pageCaptions', 'w05', ['text' => 'Zima'], '5. týden')]);
        $this->save(CalendarActor::client(), [new CalendarContentOp('pageCaptions', 'w05', ['text' => 'Zima na návsi'], '5. týden')]);

        self::assertSame(['text' => 'Zima na návsi'], $this->items('pageCaptions')['w05'] ?? null);
        self::assertSame([
            ['client', 'Upravil popisek — 5. týden'],
            ['designer', 'Upravil popisek — 5. týden'],
            ['designer', 'Vytvořil kalendář'],
        ], $this->history());
    }

    public function testUnchangedOpWritesNoHistory(): void
    {
        $op = new CalendarContentOp('events', 'designerEvent', self::event('Masopust'), '6. 2.');

        $this->save(CalendarActor::designer(), [$op]);
        // Sent again — also with the keys in another order, as jsonb stores them.
        $this->save(CalendarActor::designer(), [$op]);
        $this->save(CalendarActor::designer(), [new CalendarContentOp('events', 'designerEvent', array_reverse(self::event('Masopust'), true), '6. 2.')]);
        // Deleting what does not exist is unchanged too.
        $this->save(CalendarActor::designer(), [new CalendarContentOp('events', 'neverExisted', null)]);

        self::assertCount(2, $this->history());
        self::assertSame(4, $this->calendar()->revision);
    }

    public function testAtMostThirtyHistoryEntriesPerRequest(): void
    {
        $ops = [];

        for ($i = 0; $i < SaveCalendarHandler::MAX_HISTORY_ENTRIES + 5; $i++) {
            $ops[] = new CalendarContentOp('events', sprintf('bulkEvent%03d', $i), self::event('Akce ' . $i), '1. 6.');
        }

        // Coalesced sentences count once, however many items they cover.
        $ops[] = new CalendarContentOp('moonEdits', 'moonEdit0001', ['op' => 'add', 'date' => '2027-01-05', 'phase' => 2]);
        $ops[] = new CalendarContentOp('moonEdits', 'moonEdit0002', ['op' => 'remove', 'date' => '2027-01-06', 'phase' => 2]);

        $this->save(CalendarActor::designer(), $ops);

        $history = $this->history();
        self::assertCount(SaveCalendarHandler::MAX_HISTORY_ENTRIES + 2, $history);
        self::assertSame(['designer', '… a dalších 6 změn'], $history[0]);
        self::assertCount(35, $this->items('events'));
        self::assertCount(2, $this->items('moonEdits'));
    }

    public function testDateRangeFollowsAYearChangedInTheSameSave(): void
    {
        // 2028-06-01 is outside calendar year 2027…
        $this->assertRefused(InvalidCalendarChange::class, CalendarActor::designer(), [new CalendarContentOp('events', 'nextYearEvent', self::event('Pouť', '2028-06-01'))]);

        // …and fine once the same request moves the calendar to 2028.
        $this->save(CalendarActor::designer(), [new CalendarContentOp('events', 'nextYearEvent', self::event('Pouť', '2028-06-01'))], (object) ['year' => 2028]);

        self::assertSame(2028, $this->calendar()->year);
        self::assertArrayHasKey('nextYearEvent', $this->items('events'));

        // December before and January after the year belong to it; 29 February only in a leap year.
        $this->save(CalendarActor::designer(), [
            new CalendarContentOp('events', 'edgeBefore001', self::event('Advent', '2027-12-01')),
            new CalendarContentOp('events', 'edgeAfter0001', self::event('Tři králové', '2029-01-31')),
            new CalendarContentOp('events', 'leapDay00001', self::event('Přestupný den', '2028-02-29')),
        ]);
        $this->assertRefused(InvalidCalendarChange::class, CalendarActor::designer(), [new CalendarContentOp('events', 'tooEarly0001', self::event('X', '2027-11-30'))]);
        $this->assertRefused(InvalidCalendarChange::class, CalendarActor::designer(), [new CalendarContentOp('events', 'tooLate00001', self::event('X', '2029-02-01'))]);
    }

    public function testUnknownAssetIsRejectedAndNothingIsApplied(): void
    {
        $unknown = '0196f000-0000-7000-8000-000000000001';

        $this->assertRefused(InvalidCalendarChange::class, CalendarActor::designer(), [
            new CalendarContentOp('events', 'validEvent001', self::event('Pouť')),
            new CalendarContentOp('pagePhotos', 'w05', ['asset' => $unknown, 'zoom' => 1, 'px' => 0.5, 'py' => 0.5]),
        ]);

        $layout = json_decode(str_replace('"logo": {"asset": null', sprintf('"logo": {"asset": "%s"', $unknown), TestDataFixture::CALENDAR_LAYOUT_JSON));
        self::assertInstanceOf(\stdClass::class, $layout);
        $this->assertRefused(InvalidCalendarChange::class, CalendarActor::designer(), [], (object) ['preset' => 'photoLeft', 'layout' => $layout]);

        $calendar = $this->calendar();
        self::assertSame(0, $calendar->revision);
        self::assertArrayNotHasKey('events', $calendar->content);
        self::assertNull($calendar->layout);
    }

    public function testClientRequestWithAnyDesignKeyIsRefusedWhole(): void
    {
        $this->assertRefused(CalendarChangeForbidden::class, CalendarActor::client(), [new CalendarContentOp('events', 'clientEvent01', self::event('Pouť'))], (object) ['step' => 2]);
        $this->assertRefused(CalendarChangeForbidden::class, CalendarActor::client(), [], null, true);
        $this->assertRefused(CalendarChangeForbidden::class, CalendarActor::client(), [
            new CalendarContentOp('events', 'clientEvent01', self::event('Pouť')),
            new CalendarContentOp('shiftOps', 'shiftOp00001', null),
        ]);

        self::assertSame(0, $this->calendar()->revision);
        self::assertArrayNotHasKey('events', $this->calendar()->content);
    }

    public function testDesignHistoryIsCoalescedAndAStepOnlyPatchIsSilent(): void
    {
        $this->save(CalendarActor::designer(), [], (object) ['step' => 2]);
        self::assertCount(1, $this->history());

        $this->save(CalendarActor::designer(), [], (object) ['name' => 'První']);
        $this->save(CalendarActor::designer(), [], (object) ['name' => 'Druhý']);
        // Unchanged value: nothing to record.
        $this->save(CalendarActor::designer(), [], (object) ['name' => 'Druhý']);

        self::assertSame([['designer', 'Upravil vzhled a nastavení'], ['designer', 'Vytvořil kalendář']], $this->history());

        // Another piece of work in between starts a new entry.
        $this->save(CalendarActor::designer(), [new CalendarContentOp('clubs', 'clubHasici01', ['code' => 'SDH', 'name' => 'Hasiči', 'pos' => 0])]);
        $this->save(CalendarActor::designer(), [], (object) ['linkElements' => false]);

        self::assertSame('Upravil vzhled a nastavení', $this->history()[0][1]);
        self::assertCount(4, $this->history());
    }

    public function testDesignerMayMoveACommentDateButNotItsText(): void
    {
        $this->save(CalendarActor::client(), [new CalendarContentOp('comments', 'comment00001', ['date' => '2027-06-01', 'text' => 'Jiná fotka'], '1. 6.')]);

        // A year change shifts every dated item, comments included (§E.7).
        $this->save(
            CalendarActor::designer(),
            [new CalendarContentOp('comments', 'comment00001', ['date' => '2028-06-01', 'text' => 'Jiná fotka'])],
            (object) ['year' => 2028],
        );

        $comment = $this->items('comments')['comment00001'] ?? null;
        self::assertIsArray($comment);
        self::assertSame('2028-06-01', $comment['date'] ?? null);
        self::assertSame('client', $comment['author'] ?? null);

        $this->assertRefused(InvalidCalendarChange::class, CalendarActor::designer(), [new CalendarContentOp('comments', 'comment00001', ['date' => '2028-06-01', 'text' => 'Přepsáno'])]);
    }

    /**
     * @param list<CalendarContentOp> $ops
     */
    private function save(CalendarActor $actor, array $ops, null|\stdClass $design = null, bool $finish = false): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new SaveCalendar($this->calendarId, $actor, $design, $ops, $finish));
    }

    /**
     * @param class-string<\Throwable> $exception
     * @param list<CalendarContentOp> $ops
     */
    private function assertRefused(string $exception, CalendarActor $actor, array $ops, null|\stdClass $design = null, bool $finish = false): void
    {
        try {
            $this->save($actor, $ops, $design, $finish);
            self::fail('The save should have been refused.');
        } catch (HandlerFailedException $failed) {
            self::assertInstanceOf($exception, $failed->getPrevious());
        }

        // A refused handler leaves a dirty unit of work behind — start clean.
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function calendar(): Calendar
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $calendar = $entityManager->find(Calendar::class, $this->calendarId);
        self::assertInstanceOf(Calendar::class, $calendar);

        return $calendar;
    }

    /**
     * @return array<mixed> the stored items of a content collection
     */
    private function items(string $collection): array
    {
        $items = $this->calendar()->content[$collection] ?? null;
        self::assertIsArray($items, $collection);

        return $items;
    }

    /**
     * @return list<array{string, string}> [author kind, description], newest first
     */
    private function history(): array
    {
        return array_map(
            static fn ($entry): array => [$entry->authorKind->value, $entry->description],
            self::getContainer()->get(CalendarHistoryRepository::class)->latest($this->calendarId, 200),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function event(string $name, string $date = '2027-06-01'): array
    {
        return ['date' => $date, 'name' => $name, 'club' => null, 'icon' => null];
    }
}
