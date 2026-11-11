<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Calendar;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\Calendar\DescribeCalendarChange;

/**
 * Every history sentence of docs/calendar/architecture.md §C.6.
 *
 * @covers \WBoost\Web\Services\Calendar\DescribeCalendarChange
 */
final class DescribeCalendarChangeTest extends TestCase
{
    private const array CONTENT = [
        'v' => 1,
        'wasteKinds' => ['bio' => ['name' => 'Bio odpad'], 'kom' => ['name' => 'Komunální odpad']],
    ];

    /**
     * @param null|array<string, mixed> $old
     * @param null|array<string, mixed> $new
     */
    #[DataProvider('provideChanges')]
    public function testSentence(null|string $expected, string $collection, null|array $old, null|array $new, string $label = ''): void
    {
        self::assertSame($expected, (new DescribeCalendarChange())->describe($collection, $old, $new, $label, self::CONTENT));
    }

    /**
     * @return iterable<array{0: null|string, 1: string, 2: null|array<string, mixed>, 3: null|array<string, mixed>, 4?: string}>
     */
    public static function provideChanges(): iterable
    {
        $photo = ['asset' => 'a', 'zoom' => 1, 'px' => 0.5, 'py' => 0.5];

        yield ['Nahrál fotku — 5. týden', 'pagePhotos', null, $photo, '5. týden'];
        yield ['Vyměnil fotku — 5. týden', 'pagePhotos', $photo, [...$photo, 'asset' => 'b'], '5. týden'];
        yield ['Posunul fotku — 1. a 2. týden', 'pagePhotos', $photo, [...$photo, 'zoom' => 1.5], '1. a 2. týden'];
        yield ['Odebral fotku — 5. týden', 'pagePhotos', $photo, null, '5. týden'];
        yield ['Nahrál fotku', 'pagePhotos', null, $photo];

        yield ['Upravil popisek — 5. týden', 'pageCaptions', null, ['text' => 'Zima'], '5. týden'];
        yield ['Upravil popisek — 5. týden', 'pageCaptions', ['text' => 'Zima'], ['text' => 'Jaro'], '5. týden'];
        yield ['Odebral popisek — 5. týden', 'pageCaptions', ['text' => 'Zima'], null, '5. týden'];

        yield ['Upravil obrázky strany — Titulní strana', 'pageImages', null, ['head' => null, 'bg' => 'a'], 'Titulní strana'];
        yield ['Odebral obrázky strany — Titulní strana', 'pageImages', ['head' => null, 'bg' => 'a'], null, 'Titulní strana'];
        yield ['Nahrál QR kód — 5. týden', 'pageQr', null, ['asset' => 'a'], '5. týden'];
        yield ['Nahrál QR kód — 5. týden', 'pageQr', ['asset' => 'a'], ['asset' => 'b'], '5. týden'];
        yield ['Odebral QR kód — 5. týden', 'pageQr', ['asset' => 'a'], null, '5. týden'];

        yield ['Přidal akci „Den dětí“ — 1. 6.', 'events', null, ['name' => 'Den dětí'], '1. 6.'];
        yield ['Upravil akci „Den dětí“ — 1. 6.', 'events', ['name' => 'Den deti'], ['name' => 'Den dětí'], '1. 6.'];
        yield ['Odebral akci „Den dětí“', 'events', ['name' => 'Den dětí'], null, '1. 6.'];

        // content of the cover, the back and the special pages
        $cover = ['title' => 'Kalendář', 'subtitle' => '', 'note' => '', 'photo' => null];
        $coverPhoto = ['asset' => 'a', 'zoom' => 1, 'px' => 0.5, 'py' => 0.5];
        yield ['Upravil texty strany — Titulní strana', 'pageContent', null, $cover, 'Titulní strana'];
        yield ['Upravil texty strany — Úvodní slovo', 'pageContent', ['body' => 'a', 'photo' => null], ['body' => 'b', 'photo' => null], 'Úvodní slovo'];
        yield ['Nahrál fotku — Titulní strana', 'pageContent', $cover, [...$cover, 'photo' => $coverPhoto], 'Titulní strana'];
        yield ['Nahrál fotku — Titulní strana', 'pageContent', null, ['title' => '', 'subtitle' => '', 'note' => '', 'photo' => $coverPhoto], 'Titulní strana'];
        yield ['Vyměnil fotku — Titulní strana', 'pageContent', [...$cover, 'photo' => $coverPhoto], [...$cover, 'photo' => [...$coverPhoto, 'asset' => 'b']], 'Titulní strana'];
        yield ['Posunul fotku — Titulní strana', 'pageContent', [...$cover, 'photo' => $coverPhoto], [...$cover, 'photo' => [...$coverPhoto, 'px' => 0.1]], 'Titulní strana'];
        yield ['Odebral fotku — Titulní strana', 'pageContent', [...$cover, 'photo' => $coverPhoto], $cover, 'Titulní strana'];
        yield ['Upravil texty strany — Titulní strana', 'pageContent', $cover, [...$cover, 'title' => 'Jiný', 'photo' => $coverPhoto], 'Titulní strana'];
        yield ['Smazal obsah strany — Zadní strana', 'pageContent', $cover, null, 'Zadní strana'];
        yield ['Upravil vzhled strany — Titulní strana', 'pageSetup', null, ['layout' => 1, 'logo' => 'tc', 'logoSize' => 'm'], 'Titulní strana'];
        yield ['Upravil vzhled strany', 'pageSetup', ['logo' => true], null];

        $group = ['page' => 'sp:contacts', 'heading' => 'Obecní úřad', 'lines' => [['label' => 'T:', 'value' => '1']], 'highlight' => false, 'pos' => 0];
        yield ['Přidal skupinu „Obecní úřad“ — Důležité kontakty', 'pageGroups', null, $group, 'cokoliv'];
        yield ['Upravil skupinu „Obecní úřad“ — Důležité kontakty', 'pageGroups', $group, [...$group, 'lines' => [['label' => 'T:', 'value' => '2']]]];
        yield ['Upravil skupinu „Úřad“ — Důležité kontakty', 'pageGroups', $group, [...$group, 'heading' => 'Úřad', 'pos' => 3]];
        yield ['Změnil pořadí skupin — Důležité kontakty', 'pageGroups', $group, [...$group, 'pos' => 3]];
        yield ['Odebral skupinu „Obecní úřad“ — Důležité kontakty', 'pageGroups', $group, null];
        yield ['Přidal skupinu bez názvu — Plánovací kalendář svozu odpadů', 'pageGroups', null, [...$group, 'heading' => '  ', 'page' => 'sp:waste']];

        yield ['Přidal spolek „Hasiči“', 'clubs', null, ['name' => 'Hasiči']];
        yield ['Upravil spolek „Hasiči“', 'clubs', ['name' => 'Hasici'], ['name' => 'Hasiči']];
        yield ['Odebral spolek „Hasiči“', 'clubs', ['name' => 'Hasiči'], null];

        yield ['Přidal jméno „Bedřich“ — 1. 3.', 'names', null, ['name' => 'Bedřich'], '1. 3.'];
        yield ['Upravil jméno „Bedřich“ — 1. 3.', 'names', ['name' => 'Bedrich'], ['name' => 'Bedřich'], '1. 3.'];
        yield ['Odebral jméno „Bedřich“', 'names', ['name' => 'Bedřich'], null, '1. 3.'];

        yield ['Přidal druh odpadu „Sklo“', 'wasteKinds', null, ['name' => 'Sklo']];
        yield ['Upravil svoz — Sklo', 'wasteKinds', ['name' => 'Sklo', 'on' => false], ['name' => 'Sklo', 'on' => true]];
        yield ['Odebral druh odpadu „Sklo“', 'wasteKinds', ['name' => 'Sklo'], null];

        yield ['Přidal svoz Bio odpad — 2. 6.', 'wasteExtras', null, ['kind' => 'bio', 'date' => '2027-06-02'], '2. 6.'];
        yield ['Odebral svoz Bio odpad — 2. 6.', 'wasteExtras', ['kind' => 'bio', 'date' => '2027-06-02'], null, '2. 6.'];
        // The kind is gone meanwhile — still a sentence.
        yield ['Přidal svoz — 2. 6.', 'wasteExtras', null, ['kind' => 'gone', 'date' => '2027-06-02'], '2. 6.'];

        yield ['Komentář k 1. 6.: Prosíme jinou fotku', 'comments', null, ['text' => "Prosíme\n jinou fotku"], '1. 6.'];
        yield ['Komentář: Bez data', 'comments', null, ['text' => 'Bez data']];
        yield ['Komentář k 1. 6.: ' . str_repeat('a', 159) . '…', 'comments', null, ['text' => str_repeat('a', 400)], '1. 6.'];
        // A comment whose date the designer moved (year change) is no news.
        yield [null, 'comments', ['text' => 'x', 'date' => '2027-06-01'], ['text' => 'x', 'date' => '2028-06-01'], '1. 6.'];

        yield ['Upravil fáze měsíce', 'moonEdits', null, ['op' => 'add']];
        yield ['Upravil fáze měsíce', 'moonEdits', ['op' => 'add'], null];

        yield ['Přidal provoz „Válcovna“', 'shiftOps', null, ['name' => 'Válcovna']];
        yield ['Upravil šichtovnici — Válcovna', 'shiftOps', ['name' => 'Válcovna', 'pos' => 0], ['name' => 'Válcovna', 'pos' => 1]];
        yield ['Odebral provoz „Válcovna“', 'shiftOps', ['name' => 'Válcovna'], null];

        $custom = ['kind' => 'image', 'name' => 'Mapa obce', 'asset' => 'a', 'text' => null, 'on' => true];

        yield ['Přidal vlastní stránku „Mapa obce“', 'specialPages', null, $custom];
        yield ['Vyměnil vlastní stránku „Mapa obce“', 'specialPages', $custom, [...$custom, 'asset' => 'b']];
        yield ['Vyměnil vlastní stránku „Slovo“', 'specialPages', ['kind' => 'text', 'name' => 'Slovo', 'asset' => null, 'text' => 'a'], ['kind' => 'text', 'name' => 'Slovo', 'asset' => null, 'text' => 'b']];
        yield ['Upravil speciální stránky', 'specialPages', $custom, [...$custom, 'on' => false]];
        yield ['Upravil speciální stránky', 'specialPages', null, ['kind' => 'intro', 'name' => '', 'asset' => null, 'text' => null, 'on' => true]];
        yield ['Upravil speciální stránky', 'specialPages', ['kind' => 'year', 'on' => true], ['kind' => 'year', 'on' => false]];
        yield ['Smazal stránku „Mapa obce“', 'specialPages', $custom, null];

        yield [null, 'unknown', null, ['a' => 1]];
    }

    public function testComposedSentencesAndCoalescing(): void
    {
        self::assertSame('Načetl layout z „Obecní kalendář“', DescribeCalendarChange::layoutFromCalendar('Obecní kalendář'));
        self::assertSame('… a dalších 6 změn', DescribeCalendarChange::andMore(6));

        self::assertTrue(DescribeCalendarChange::isCoalesced('Upravil fáze měsíce'));
        self::assertTrue(DescribeCalendarChange::isCoalesced('Upravil speciální stránky'));
        self::assertFalse(DescribeCalendarChange::isCoalesced('Přidal akci „Pouť“'));
    }
}
