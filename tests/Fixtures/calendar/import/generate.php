<?php

declare(strict_types=1);

/*
 * Builds the spreadsheet fixtures of „Nahrát z Excelu“ (K10b) — realistic
 * municipal tables in every supported format and shape. No real municipal
 * file was available; these follow docs/calendar/excel-import-plan.md §2.
 * The files are committed; re-run after a change and commit the result:
 *
 *   docker compose exec web php tests/Fixtures/calendar/import/generate.php
 */

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

$dir = __DIR__;

/**
 * @param array<string, list<list<null|string|array{float, string}>>> $sheets a `[serial, format]` pair is a real date cell
 */
function book(array $sheets): Spreadsheet
{
    $book = new Spreadsheet();
    $book->removeSheetByIndex(0);

    foreach ($sheets as $title => $rows) {
        $sheet = $book->createSheet();
        $sheet->setTitle($title);

        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                if ($value === null) {
                    continue;
                }

                $coordinate = [$c + 1, $r + 1];

                if (is_array($value)) {
                    $sheet->setCellValue($coordinate, $value[0]);
                    $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode($value[1]);
                } else {
                    $sheet->setCellValueExplicit($coordinate, $value, str_starts_with($value, '=') ? 'f' : 's');
                }
            }
        }
    }

    $book->setActiveSheetIndex(0);

    return $book;
}
/** @return array{float, string} a real date cell */
function d(int $m, int $day, int $y = 2027): array
{
    return [Date::formattedPHPToExcel($y, $m, $day), 'd.m.yyyy'];
}

// A — one row per collection (xlsx): a title row, header, real date cells, a formula, an unknown kind,
// a bad date, a date outside the calendar's range, an empty row in between.
$a = [
    ['Harmonogram svozu odpadů 2027 – obec Testov'],
    [],
    ['Datum svozu', 'Druh odpadu', 'Poznámka / místo'],
];
foreach ([[1, 7], [1, 21], [2, 4], [2, 18], [3, 4]] as [$m, $day]) {
    $a[] = [d($m, $day), 'Komunální odpad', ''];
}
foreach ([[1, 14], [2, 11], [3, 11]] as [$m, $day]) {
    $a[] = [d($m, $day), 'PLASTY', 'žluté pytle'];
}
$a[] = [d(1, 28), 'papír', ''];
$a[] = [d(4, 2), 'Bioodpad', 'hnědé nádoby'];
$a[] = [];
$a[] = [d(4, 25), 'Nebezpečný odpad', 'u hasičské zbrojnice'];
$a[] = ['neznámé datum', 'PLASTY', ''];
$a[] = [d(3, 1, 2029), 'PLASTY', ''];
$a[] = ['=DATE(2027,5,6)', 'Komunální odpad', ''];
IOFactory::createWriter(book(['Svoz 2027' => $a, 'Kontakty' => [['Sběrný dvůr', 'Po 8–12']]]), 'Xlsx')->save("$dir/odpady-radky.xlsx");

// B — one column per kind (xls, Excel 97–2003): date cells and typed text dates mixed.
$b = [['Komunál', 'Plast', 'Papír']];
$b[] = [d(1, 7), '14.1.2027', d(1, 28)];
$b[] = [d(1, 21), '11. 2. 2027', d(2, 25)];
$b[] = [d(2, 4), '11. 3.', null];
$b[] = [d(2, 18), null, null];
IOFactory::createWriter(book(['List1' => $b]), 'Xls')->save("$dir/odpady-sloupce.xls");

// C — months × kinds, the days listed (ods).
$months = ['Leden', 'Únor', 'Březen', 'Duben', 'Květen', 'Červen', 'Červenec', 'Srpen', 'Září', 'Říjen', 'Listopad', 'Prosinec'];
$c = [['Měsíc', 'Komunální odpad', 'Plasty', 'Papír', 'Sklo']];
foreach ($months as $i => $month) {
    $c[] = [$month, '7., 21.', '14.', $i % 2 === 0 ? '28.' : '', $i === 5 ? '3. a 17.' : ''];
}
IOFactory::createWriter(book(['Svozy' => $c]), 'Ods')->save("$dir/odpady-mesice.ods");

// D — one column of dates (CSV the way Czech Excel saves it: Windows-1250, `;`, CRLF).
$lines = ['Termíny svozu – sklo (zelené kontejnery)', '15.01.2027', '12.02.2027', '12.03.2027', '09.04.2027', '30.02.2027', ''];
file_put_contents("$dir/odpady-sklo-1250.csv", iconv('UTF-8', 'CP1250', implode("\r\n", $lines)));

// Events (xlsx): name, organiser by name or code, extra columns, a multi-day event, a duplicate row.
$e = [
    ['Datum', 'Název akce', 'Pořadatel', 'Čas', 'Místo konání'],
    [d(1, 17), 'Tříkrálová sbírka – poděkování', 'Obec Testov', '17:00', 'sál OÚ'],
    [d(2, 14), 'Hasičský ples', 'SDH Testov', '20:00', 'sokolovna'],
    [d(2, 14), 'Hasičský ples', 'SDH Testov', '20:00', 'sokolovna'],
    ['12.–14. 6. 2027', 'Pouťové oslavy', 'TJ Sokol Testov', '', 'náves'],
    [d(4, 30), 'Pálení čarodějnic', 'SDH', '18:00', 'hřiště'],
    [d(6, 1), 'Dětský den', '', '14:00', 'hřiště'],
    ['', 'Akce bez data', 'SDH', '', ''],
    [d(9, 5), '', 'TJ Sokol Testov', '', ''],
];
IOFactory::createWriter(book(['Akce 2027' => $e]), 'Xlsx')->save("$dir/akce.xlsx");

// Events as CSV, Windows-1250 with `;` and quoted fields.
$csv = ["Datum;Akce;Spolek", '6.3.2027;"Maškarní ples; pro děti";ZŠ Testov', '24. 12. 2027;Zpívání u stromu;', '1.1.2028;Novoroční výšlap;KČT'];
file_put_contents("$dir/akce-1250.csv", iconv('UTF-8', 'CP1250', implode("\r\n", $csv)));

// What the reader makes of them — the input of the node suite `import` and a contract the PHP
// test pins (CalendarTableReaderTest::testTablesJsonIsWhatTheReaderReads).
$tables = [];
foreach (['odpady-radky.xlsx', 'odpady-sloupce.xls', 'odpady-mesice.ods', 'odpady-sklo-1250.csv', 'akce.xlsx', 'akce-1250.csv'] as $name) {
    $tables[$name] = (new WBoost\Web\Services\Calendar\CalendarTableReader())->read("$dir/$name", $name);
}
file_put_contents("$dir/tables.json", json_encode($tables, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

echo "ok\n";
