<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Calendar;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Exceptions\CalendarChangeForbidden;
use WBoost\Web\Exceptions\InvalidCalendarChange;
use WBoost\Web\Services\Calendar\CalendarContentRules;
use WBoost\Web\Value\Calendar\CalendarActor;
use WBoost\Web\Value\Calendar\CalendarContentOp;

/**
 * The content collection registry of docs/calendar/architecture.md §B.6.
 *
 * @covers \WBoost\Web\Services\Calendar\CalendarContentRules
 */
final class CalendarContentRulesTest extends TestCase
{
    private const string ASSET = '0196f000-0000-7000-8000-00000000000a';
    private const int YEAR = 2027;

    /**
     * One valid item per collection — every key of §B.6.
     *
     * @return array<string, array{string, array<string, mixed>}> collection → [id, item]
     */
    private static function validItems(): array
    {
        return [
            'clubs' => ['clubHasici', ['code' => 'SDH', 'name' => 'Sbor dobrovolných hasičů', 'pos' => 0]],
            'events' => ['eventPout1', ['date' => '2027-06-01', 'name' => 'Pouť', 'club' => 'clubHasici', 'icon' => self::ASSET]],
            'names' => ['nameBedrich', ['date' => '2027-03-01', 'name' => 'Bedřich']],
            'wasteKinds' => ['sklo', [
                'builtin' => 'sklo', 'name' => 'Sklo', 'on' => true, 'place' => 'u obchodu', 'mode' => 'rule', 'weekdays' => [0, 3],
                'interval' => '2e', 'from' => '2027-03-01', 'to' => '2027-10-31', 'dates' => ['2027-05-05'], 'pos' => 4,
            ]],
            'wasteExtras' => ['extraBio01', ['kind' => 'bio', 'date' => '2027-06-02']],
            'moonEdits' => ['moonEdit01', ['op' => 'add', 'date' => '2026-12-24', 'phase' => 3]],
            'shiftOps' => ['shiftOp001', [
                'name' => 'Válcovna', 'shifts' => [['letter' => 'R', 'from' => '06:00', 'to' => '14:00'], ['letter' => 'N', 'from' => '22:00', 'to' => '06:00']],
                'crews' => ['A', 'B', 'C', 'D'], 'pos' => 0,
            ]],
            'specialPages' => ['customPage1', [
                'kind' => 'text', 'on' => true, 'layout' => 0, 'position' => 'back', 'nextYear' => false, 'name' => 'Slovo starosty',
                'asset' => null, 'text' => "První odstavec\nDruhý odstavec", 'fileName' => 'slovo.docx', 'pos' => 5,
            ]],
            'pagePhotos' => ['w05', ['asset' => self::ASSET, 'zoom' => 1.25, 'px' => 0.5, 'py' => 0]],
            'pageCaptions' => ['pre', ['text' => 'Zima na návsi']],
            'pageImages' => ['sp:customPage1', ['head' => null, 'bg' => self::ASSET]],
            'pageQr' => ['w52', ['asset' => self::ASSET]],
        ];
    }

    public function testEveryCollectionOfTheContentHasAValidItemAndIsRebuiltExactly(): void
    {
        $items = self::validItems();

        self::assertEqualsCanonicalizing(array_diff(CalendarContentRules::COLLECTIONS, ['comments']), array_keys($items));

        foreach ($items as $collection => [$id, $item]) {
            self::assertSame($item, self::validate(new CalendarContentOp($collection, $id, $item)), $collection);
            // A delete only needs a valid id.
            self::assertNull(self::validate(new CalendarContentOp($collection, $id, null)), $collection);
        }
    }

    public function testAnItemMustHaveExactlyTheKnownKeys(): void
    {
        foreach (self::validItems() as $collection => [$id, $item]) {
            self::assertInvalid(new CalendarContentOp($collection, $id, [...$item, 'extra' => 1]), $collection . ' + extra key');

            $missing = $item;
            unset($missing[(string) array_key_first($item)]);
            self::assertInvalid(new CalendarContentOp($collection, $id, $missing), $collection . ' − first key');
        }
    }

    /**
     * @param array<string, mixed> $change
     */
    #[DataProvider('provideInvalidValues')]
    public function testInvalidValuesAreRefused(string $collection, array $change): void
    {
        [$id, $item] = self::validItems()[$collection];

        self::assertInvalid(new CalendarContentOp($collection, $id, [...$item, ...$change]), json_encode($change, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return iterable<array{string, array<string, mixed>}>
     */
    public static function provideInvalidValues(): iterable
    {
        yield ['clubs', ['code' => 'SEDMZNA']];
        yield ['clubs', ['name' => str_repeat('a', 61)]];
        yield ['clubs', ['pos' => '1']];
        yield ['clubs', ['pos' => -1]];
        yield ['events', ['name' => str_repeat('a', 81)]];
        yield ['events', ['name' => 5]];
        yield ['events', ['date' => '1. 6. 2027']];
        yield ['events', ['date' => '2027-02-29']];
        yield ['events', ['date' => '2026-11-30']];
        yield ['events', ['date' => '2028-02-01']];
        yield ['events', ['club' => 'no spaces!']];
        yield ['events', ['icon' => 'not-an-asset-id']];
        yield ['events', ['icon' => '0196f000-0000-7000-8000-00000000dead']];
        yield ['names', ['name' => str_repeat('a', 41)]];
        yield ['wasteKinds', ['builtin' => 'kov']];
        yield ['wasteKinds', ['name' => str_repeat('a', 31)]];
        yield ['wasteKinds', ['on' => 1]];
        yield ['wasteKinds', ['place' => str_repeat('a', 41)]];
        yield ['wasteKinds', ['mode' => 'random']];
        yield ['wasteKinds', ['weekdays' => []]];
        yield ['wasteKinds', ['weekdays' => [7]]];
        yield ['wasteKinds', ['weekdays' => [1, 1]]];
        yield ['wasteKinds', ['weekdays' => ['a' => 1]]];
        yield ['wasteKinds', ['interval' => '3w']];
        yield ['wasteKinds', ['from' => '2027-10-31', 'to' => '2027-03-01']];
        yield ['wasteKinds', ['dates' => ['2027-13-01']]];
        yield ['wasteKinds', ['dates' => '2027-05-05']];
        yield ['wasteExtras', ['kind' => '']];
        yield ['moonEdits', ['op' => 'move']];
        yield ['moonEdits', ['phase' => 4]];
        yield ['shiftOps', ['shifts' => []]];
        yield ['shiftOps', ['shifts' => array_fill(0, 5, ['letter' => 'R', 'from' => '06:00', 'to' => '14:00'])]];
        yield ['shiftOps', ['shifts' => [['letter' => 'RAN', 'from' => '06:00', 'to' => '14:00']]]];
        yield ['shiftOps', ['shifts' => [['letter' => 'R', 'from' => '6:00', 'to' => '14:00']]]];
        yield ['shiftOps', ['shifts' => [['letter' => 'R', 'from' => '06:00', 'to' => '24:00']]]];
        yield ['shiftOps', ['shifts' => [['letter' => 'R', 'from' => '06:00']]]];
        yield ['shiftOps', ['crews' => ['A', 'B', 'C']]];
        yield ['shiftOps', ['crews' => ['A', 'B', 'C', 'DDD']]];
        yield ['specialPages', ['kind' => 'poster']];
        yield ['specialPages', ['position' => 'middle']];
        yield ['specialPages', ['text' => str_repeat('a', 6001)]];
        yield ['specialPages', ['fileName' => str_repeat('a', 121)]];
        yield ['specialPages', ['layout' => 1.5]];
        yield ['pagePhotos', ['asset' => null]];
        yield ['pagePhotos', ['zoom' => 0.9]];
        yield ['pagePhotos', ['zoom' => 3.1]];
        yield ['pagePhotos', ['px' => 1.01]];
        yield ['pagePhotos', ['py' => '0.5']];
        yield ['pageCaptions', ['text' => str_repeat('a', 81)]];
        yield ['pageImages', ['bg' => '0196f000-0000-7000-8000-00000000dead']];
        yield ['pageQr', ['asset' => null]];
    }

    public function testIdsAndPageKeys(): void
    {
        $caption = ['text' => 'x'];

        foreach (['pre', 'post', 'w01', 'w53', 'cover', 'back', 'sp:intro', 'sp:Ab3_dE-9'] as $pageKey) {
            self::assertSame($caption, self::validate(new CalendarContentOp('pageCaptions', $pageKey, $caption)), $pageKey);
        }

        foreach (['w5', 'w123', 'week05', 'sp:', 'sp:has space', '', 'cover2'] as $pageKey) {
            self::assertInvalid(new CalendarContentOp('pageCaptions', $pageKey, $caption), $pageKey);
        }

        $name = ['date' => '2027-03-01', 'name' => 'Bedřich'];

        foreach (['a', 'kom', 'Ab3dEf9hIjKlMnOp', str_repeat('a', 40), 'with_under-dash'] as $id) {
            self::assertSame($name, self::validate(new CalendarContentOp('names', $id, $name)), $id);
        }

        foreach (['', str_repeat('a', 41), 'sp:intro', 'a b', 'ě', '../x'] as $id) {
            self::assertInvalid(new CalendarContentOp('names', $id, $name), $id);
        }

        self::assertInvalid(new CalendarContentOp('layouts', 'abcdefgh', ['a' => 1]), 'unknown collection');
    }

    public function testItemSizeCap(): void
    {
        // 400 dates fit; the count cap stops more.
        [$id, $kind] = self::validItems()['wasteKinds'];
        $kind['dates'] = array_fill(0, CalendarContentRules::MAX_WASTE_DATES, '2027-05-05');
        self::assertNotNull(self::validate(new CalendarContentOp('wasteKinds', $id, $kind)));

        $kind['dates'][] = '2027-05-06';
        self::assertInvalid(new CalendarContentOp('wasteKinds', $id, $kind), '401 dates');

        // A special page may be larger than an ordinary item (its text).
        [$id, $page] = self::validItems()['specialPages'];
        $page['text'] = str_repeat('ž', 6000);
        self::assertNotNull(self::validate(new CalendarContentOp('specialPages', $id, $page)));
    }

    public function testClientWhitelist(): void
    {
        $rules = new CalendarContentRules();

        // Handoff: the client fills photos, captions, waste, events (+ clubs), names and comments.
        $clientMayWrite = ['pagePhotos', 'pageCaptions', 'wasteKinds', 'wasteExtras', 'events', 'clubs', 'names', 'comments'];
        $allowed = [];

        foreach (CalendarContentRules::COLLECTIONS as $collection) {
            $op = new CalendarContentOp($collection, 'abcdefgh', null);

            // The designer may write everything.
            $rules->assertWritable($op, CalendarActor::designer());

            try {
                $rules->assertWritable($op, CalendarActor::client());
                $allowed[] = $collection;
            } catch (CalendarChangeForbidden $forbidden) {
                self::assertSame('Tuto změnu může udělat jen grafik.', $forbidden->getMessage());
            }
        }

        self::assertEqualsCanonicalizing($clientMayWrite, $allowed);

        // Anything unknown is not on the whitelist either.
        $this->expectException(CalendarChangeForbidden::class);
        $rules->assertWritable(new CalendarContentOp('design', 'abcdefgh', null), CalendarActor::client());
    }

    public function testCommentsAreInsertOnlyAndStamped(): void
    {
        $now = new DateTimeImmutable('2026-11-11T10:00:00+00:00');
        $rules = new CalendarContentRules();
        $op = new CalendarContentOp('comments', 'comment001', ['date' => '2027-06-01', 'text' => '  Jiná fotka  ', 'author' => 'designer', 'at' => 'yesterday']);

        $stored = $rules->validate($op, CalendarActor::client(), self::YEAR, [], null, $now);
        self::assertSame(['date' => '2027-06-01', 'text' => 'Jiná fotka', 'author' => 'client', 'at' => '2026-11-11T10:00:00+00:00'], $stored);

        // Sent again unchanged, later: exactly the stored item (so it is skipped).
        self::assertSame($stored, $rules->validate($op, CalendarActor::designer(), self::YEAR, [], $stored, $now->modify('+1 day')));

        // The designer may move the date (year change); author and time stay.
        $moved = $rules->validate(new CalendarContentOp('comments', 'comment001', ['date' => '2027-06-02', 'text' => 'Jiná fotka']), CalendarActor::designer(), self::YEAR, [], $stored, $now->modify('+1 day'));
        self::assertSame(['date' => '2027-06-02', 'text' => 'Jiná fotka', 'author' => 'client', 'at' => '2026-11-11T10:00:00+00:00'], $moved);

        foreach ([
            'client moves the date' => [['date' => '2027-06-02', 'text' => 'Jiná fotka'], CalendarActor::client()],
            'designer rewrites the text' => [['date' => '2027-06-01', 'text' => 'Přepsáno'], CalendarActor::designer()],
            'empty text' => [['date' => '2027-06-01', 'text' => '   '], CalendarActor::client()],
            'too long' => [['date' => '2027-06-01', 'text' => str_repeat('a', 501)], CalendarActor::client()],
            'unknown key' => [['date' => '2027-06-01', 'text' => 'x', 'resolved' => true], CalendarActor::client()],
        ] as $case => [$value, $actor]) {
            try {
                $rules->validate(new CalendarContentOp('comments', 'comment001', $value), $actor, self::YEAR, [], $case === 'empty text' || $case === 'too long' || $case === 'unknown key' ? null : $stored, $now);
                self::fail($case);
            } catch (InvalidCalendarChange $invalid) {
                self::assertNotSame('', $invalid->getMessage(), $case);
            }
        }

        $this->expectException(InvalidCalendarChange::class);
        $this->expectExceptionMessage('Komentáře nelze mazat.');
        $rules->validate(new CalendarContentOp('comments', 'comment001', null), CalendarActor::designer(), self::YEAR, [], $stored, $now);
    }

    public function testAssetIdsOfAnOp(): void
    {
        $rules = new CalendarContentRules();
        $items = self::validItems();

        self::assertSame([self::ASSET], $rules->assetIds(new CalendarContentOp('events', 'eventPout1', $items['events'][1])));
        self::assertSame([self::ASSET], $rules->assetIds(new CalendarContentOp('pageImages', 'w05', $items['pageImages'][1])));
        self::assertSame([], $rules->assetIds(new CalendarContentOp('names', 'nameBedrich', $items['names'][1])));
        self::assertSame([], $rules->assetIds(new CalendarContentOp('pagePhotos', 'w05', null)));
        // Upper-case ids are the same asset.
        self::assertSame([self::ASSET], $rules->assetIds(new CalendarContentOp('pageQr', 'w05', ['asset' => strtoupper(self::ASSET)])));
    }

    /**
     * @return null|array<string, mixed>
     */
    private static function validate(CalendarContentOp $op): null|array
    {
        return (new CalendarContentRules())->validate($op, CalendarActor::designer(), self::YEAR, [self::ASSET => true], null, new DateTimeImmutable('2026-11-11'));
    }

    private static function assertInvalid(CalendarContentOp $op, string $case): void
    {
        try {
            self::validate($op);
        } catch (InvalidCalendarChange $invalid) {
            self::assertNotSame('', $invalid->getMessage());

            return;
        }

        self::fail('Should be refused: ' . $case);
    }
}
