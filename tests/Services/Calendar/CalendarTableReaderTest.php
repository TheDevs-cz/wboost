<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Calendar;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Exceptions\InvalidCalendarChange;
use WBoost\Web\Services\Calendar\CalendarTableReader;

/**
 * „Nahrát z Excelu“ (K10b): every supported format into rows of strings —
 * date cells as ISO, Windows-1250 CSV, separators, limits, refusals. The
 * fixtures (tests/Fixtures/calendar/import, built by its generate.php) are
 * realistic municipal tables; no real file was available.
 *
 * @covers \WBoost\Web\Services\Calendar\CalendarTableReader
 */
final class CalendarTableReaderTest extends TestCase
{
    private const string DIR = __DIR__ . '/../../Fixtures/calendar/import/';

    /** @var list<string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $file) {
            @unlink($file);
        }
    }

    public function testShapeAOneRowPerCollectionFromXlsxKeepsRowNumbersAndReadsDateCellsAsIso(): void
    {
        $table = (new CalendarTableReader())->read(self::DIR . 'odpady-radky.xlsx', 'odpady-radky.xlsx');

        self::assertFalse($table['truncated']);
        self::assertSame(['Svoz 2027', 'Kontakty'], array_column($table['sheets'], 'name'));
        $rows = $table['sheets'][0]['rows'];
        self::assertSame(['Harmonogram svozu odpadů 2027 – obec Testov'], $rows[0]);
        self::assertSame([], $rows[1], 'an empty row stays, so row numbers match the file');
        self::assertSame(['Datum svozu', 'Druh odpadu', 'Poznámka / místo'], $rows[2]);
        self::assertSame(['2027-01-07', 'Komunální odpad'], $rows[3], 'a date cell is ISO, trailing empty cells are cut');
        self::assertSame(['2027-01-14', 'PLASTY', 'žluté pytle'], $rows[8]);
        self::assertSame(['neznámé datum', 'PLASTY'], $rows[15]);
        self::assertSame(['2029-03-01', 'PLASTY'], $rows[16]);
        self::assertSame(['46513', 'Komunální odpad'], $rows[17], 'a formula gives its cached result, nothing is evaluated');
        self::assertCount(18, $rows);
    }

    public function testShapeBColumnsPerKindFromXls(): void
    {
        $rows = (new CalendarTableReader())->read(self::DIR . 'odpady-sloupce.xls', 'odpady-sloupce.xls')['sheets'][0]['rows'];

        self::assertSame([['Komunál', 'Plast', 'Papír'], ['2027-01-07', '14.1.2027', '2027-01-28'], ['2027-01-21', '11. 2. 2027', '2027-02-25'], ['2027-02-04', '11. 3.'], ['2027-02-18']], $rows);
    }

    public function testShapeCMonthsTimesKindsFromOds(): void
    {
        $rows = (new CalendarTableReader())->read(self::DIR . 'odpady-mesice.ods', 'odpady-mesice.ods')['sheets'][0]['rows'];

        self::assertSame(['Měsíc', 'Komunální odpad', 'Plasty', 'Papír', 'Sklo'], $rows[0]);
        self::assertSame(['Leden', '7., 21.', '14.', '28.'], $rows[1]);
        self::assertSame(['Červen', '7., 21.', '14.', '', '3. a 17.'], $rows[6]);
        self::assertCount(13, $rows);
    }

    public function testWindows1250CsvWithSemicolonsAndQuotes(): void
    {
        $reader = new CalendarTableReader();

        self::assertSame(
            [['Termíny svozu – sklo (zelené kontejnery)'], ['15.01.2027'], ['12.02.2027'], ['12.03.2027'], ['09.04.2027'], ['30.02.2027']],
            $reader->read(self::DIR . 'odpady-sklo-1250.csv', 'odpady-sklo-1250.csv')['sheets'][0]['rows'],
        );
        self::assertSame(
            [['Datum', 'Akce', 'Spolek'], ['6.3.2027', 'Maškarní ples; pro děti', 'ZŠ Testov'], ['24. 12. 2027', 'Zpívání u stromu'], ['1.1.2028', 'Novoroční výšlap', 'KČT']],
            $reader->read(self::DIR . 'akce-1250.csv', 'akce-1250.csv')['sheets'][0]['rows'],
        );
    }

    public function testEventsTableFromXlsx(): void
    {
        $rows = (new CalendarTableReader())->read(self::DIR . 'akce.xlsx', 'akce.xlsx')['sheets'][0]['rows'];

        self::assertSame(['2027-02-14', 'Hasičský ples', 'SDH Testov', '20:00', 'sokolovna'], $rows[2]);
        self::assertSame(['12.–14. 6. 2027', 'Pouťové oslavy', 'TJ Sokol Testov', '', 'náves'], $rows[4]);
        self::assertSame(['', 'Akce bez data', 'SDH'], $rows[7]);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function textFiles(): iterable
    {
        yield 'UTF-8 with BOM, commas' => ["\xEF\xBB\xBFDatum,Druh\r\n7. 1. 2027,Plast\r\n", 'svoz.csv', 'Plast'];
        yield 'UTF-8, semicolons, LF' => ["Datum;Druh\n7. 1. 2027;Papír\n", 'svoz.csv', 'Papír'];
        yield 'TSV saved as .txt' => ["Datum\tDruh\n7. 1. 2027\tSklo – bílé\n", 'svoz.txt', 'Sklo – bílé'];
        yield 'Windows-1250 tab separated' => [(string) iconv('UTF-8', 'CP1250', "Datum\tDruh\n7. 1. 2027\tŽluté pytle\n"), 'svoz.tsv', 'Žluté pytle'];
    }

    #[DataProvider('textFiles')]
    public function testTextTablesInEveryEncodingAndSeparator(string $bytes, string $name, string $kind): void
    {
        $rows = (new CalendarTableReader())->read($this->file($bytes), $name)['sheets'][0]['rows'];

        self::assertSame([['Datum', 'Druh'], ['7. 1. 2027', $kind]], $rows);
    }

    public function testAnXlsxWithoutExtensionIsRecognisedByItsContent(): void
    {
        $rows = (new CalendarTableReader())->read(self::DIR . 'odpady-radky.xlsx', 'harmonogram')['sheets'][0]['rows'];

        self::assertSame(['2027-01-07', 'Komunální odpad'], $rows[3]);
    }

    public function testLargeTablesAreCutAndSaySo(): void
    {
        $lines = ['Datum;Druh'];
        for ($i = 0; $i < CalendarTableReader::MAX_ROWS + 50; ++$i) {
            $lines[] = '7. 1. 2027;Plast';
        }

        $table = (new CalendarTableReader())->read($this->file(implode("\n", $lines)), 'velka.csv');

        self::assertTrue($table['truncated']);
        self::assertCount(CalendarTableReader::MAX_ROWS, $table['sheets'][0]['rows']);

        $wide = new Spreadsheet();
        $wide->getActiveSheet()->fromArray([array_fill(0, CalendarTableReader::MAX_COLUMNS + 5, 'x')]);
        $path = $this->file('');
        IOFactory::createWriter($wide, 'Xlsx')->save($path);
        $table = (new CalendarTableReader())->read($path, 'siroka.xlsx');
        self::assertTrue($table['truncated']);
        self::assertCount(CalendarTableReader::MAX_COLUMNS, $table['sheets'][0]['rows'][0]);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refused(): iterable
    {
        yield 'a picture named .xlsx' => ["\x89PNG\r\n\x1a\n" . str_repeat("\0", 64), 'svoz.xlsx', CalendarTableReader::UNREADABLE];
        yield 'binary bytes named .csv' => ["ab\0cd", 'svoz.csv', CalendarTableReader::UNREADABLE];
        yield 'a PDF' => ['%PDF-1.4 nothing', 'svoz.pdf', CalendarTableReader::UNREADABLE];
        yield 'an empty CSV' => ["\r\n;;\r\n", 'prazdny.csv', CalendarTableReader::EMPTY];
    }

    #[DataProvider('refused')]
    public function testWhatIsNotATableIsRefusedInCzech(string $bytes, string $name, string $message): void
    {
        $this->expectException(InvalidCalendarChange::class);
        $this->expectExceptionMessage($message);

        (new CalendarTableReader())->read($this->file($bytes), $name);
    }

    public function testDelimiterPrefersTheMostFrequentAndSemicolonOnATie(): void
    {
        self::assertSame(';', CalendarTableReader::delimiter('a;b,c'));
        self::assertSame(',', CalendarTableReader::delimiter("a,b,c;d\n1,2,3"));
        self::assertSame("\t", CalendarTableReader::delimiter("a\tb\tc"));
        self::assertSame(';', CalendarTableReader::delimiter('jen text'));
    }

    private function file(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'table-');
        self::assertIsString($path);
        file_put_contents($path, $bytes);
        $this->temp[] = $path;

        return $path;
    }
}
