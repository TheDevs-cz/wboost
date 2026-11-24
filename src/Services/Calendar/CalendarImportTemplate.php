<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use WBoost\Web\Entity\Calendar;

/**
 * „Stáhnout vzor“ of the spreadsheet import (K10b): an `.xlsx` built for one
 * calendar — the table in the shape the import reads first (one row per
 * collection / per event), the calendar's year in the example dates, its
 * waste kinds / clubs offered as a drop-down list, and a sheet „Návod“.
 * Answered as a buffered string (never a flushing StreamedResponse — see
 * CLAUDE.md, FrankenPHP).
 */
final class CalendarImportTemplate
{
    public const array KINDS = ['waste', 'events'];

    public function fileName(Calendar $calendar, string $kind): string
    {
        return sprintf('%s-vzor-%d.xlsx', $kind === 'waste' ? 'odpady' : 'akce', $calendar->year);
    }

    public function build(Calendar $calendar, string $kind): string
    {
        $book = new Spreadsheet();
        $book->getProperties()->setCreator('WBoost')->setTitle($kind === 'waste' ? 'Svoz odpadů' : 'Akce a spolky');

        $sheet = $book->getActiveSheet();
        $lists = new Worksheet($book, 'Seznamy');

        if ($kind === 'waste') {
            $this->waste($calendar, $sheet, $lists);
        } else {
            $this->events($calendar, $sheet, $lists);
        }

        $book->addSheet($lists);
        $lists->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $guide = new Worksheet($book, 'Návod');
        $book->addSheet($guide);
        $lines = $kind === 'waste'
            ? [
                'Jak vyplnit tabulku svozu odpadů',
                'Každý svoz je jeden řádek: datum (např. 7. 1. ' . $calendar->year . ') a druh odpadu. Místo svozu je nepovinné.',
                'Druh vyberte ze seznamu, nebo napište vlastní — v kalendáři ho pak přiřadíte nebo založíte jako nový.',
                'Termíny jednoho druhu z tabulky nahradí jeho dosavadní termíny (při načtení lze zvolit „Přidat k termínům“).',
                'Kalendář přijme data od 1. 12. ' . ($calendar->year - 1) . ' do 31. 1. ' . ($calendar->year + 1) . '.',
            ]
            : [
                'Jak vyplnit tabulku akcí',
                'Každá akce je jeden řádek: datum (např. 14. 2. ' . $calendar->year . '), název akce a pořadatel.',
                'Pořadatele zadejte zkratkou (nejvýše 6 znaků, např. SDH) nebo názvem spolku — známý spolek se přiřadí, nový se založí.',
                'Akce, která v kalendáři už je (stejné datum, název i pořadatel), se znovu nepřidá.',
                'Kalendář přijme data od 1. 12. ' . ($calendar->year - 1) . ' do 31. 1. ' . ($calendar->year + 1) . '.',
            ];

        foreach ($lines as $i => $line) {
            $guide->setCellValue('A' . ($i + 1), $line);
        }

        $guide->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $guide->getColumnDimension('A')->setWidth(110);
        $book->setActiveSheetIndex(0);

        $writer = new Xlsx($book);
        $handle = fopen('php://temp', 'r+b');
        \assert($handle !== false);
        $writer->save($handle);
        rewind($handle);
        $bytes = (string) stream_get_contents($handle);
        fclose($handle);
        $book->disconnectWorksheets();

        return $bytes;
    }

    private function waste(Calendar $calendar, Worksheet $sheet, Worksheet $lists): void
    {
        $sheet->setTitle('Odpady');
        $names = [];

        foreach (self::sortedItems($calendar->content['wasteKinds'] ?? []) as $kind) {
            $name = self::string($kind['name'] ?? null);

            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        if ($names === []) {
            $names = ['Komunální odpad', 'Plast', 'Papír'];
        }

        $this->header($sheet, ['Datum', 'Druh odpadu', 'Místo svozu']);
        $examples = [[1, 7, $names[0], ''], [1, 21, $names[0], ''], [2, 3, $names[1] ?? $names[0], 'u obecního úřadu']];

        foreach ($examples as $i => [$month, $day, $name, $place]) {
            $this->date($sheet, 'A' . ($i + 2), $calendar->year, $month, $day);
            $sheet->setCellValue('B' . ($i + 2), $name);
            $sheet->setCellValue('C' . ($i + 2), $place);
        }

        $this->list($sheet, $lists, $names, 'B', 'Druh odpadu');
        $sheet->getColumnDimension('A')->setWidth(14);
        $sheet->getColumnDimension('B')->setWidth(28);
        $sheet->getColumnDimension('C')->setWidth(30);
    }

    private function events(Calendar $calendar, Worksheet $sheet, Worksheet $lists): void
    {
        $sheet->setTitle('Akce');
        $codes = [];
        $clubs = [];

        foreach (self::sortedItems($calendar->content['clubs'] ?? []) as $club) {
            $code = self::string($club['code'] ?? null);

            if ($code !== '' && !in_array($code, $codes, true)) {
                $codes[] = $code;
                $clubs[] = [$code, self::string($club['name'] ?? null)];
            }
        }

        $this->header($sheet, ['Datum', 'Název akce', 'Zkratka spolku', 'Název spolku']);
        $first = $clubs[0] ?? ['SDH', 'Sbor dobrovolných hasičů'];
        $examples = [[2, 14, 'Hasičský ples', $first[0], $first[1]], [6, 1, 'Dětský den', '', '']];

        foreach ($examples as $i => [$month, $day, $name, $code, $club]) {
            $this->date($sheet, 'A' . ($i + 2), $calendar->year, $month, $day);
            $sheet->setCellValue('B' . ($i + 2), $name);
            $sheet->setCellValue('C' . ($i + 2), $code);
            $sheet->setCellValue('D' . ($i + 2), $club);
        }

        if ($codes !== []) {
            $this->list($sheet, $lists, $codes, 'C', 'Zkratka spolku');
        }

        $sheet->getColumnDimension('A')->setWidth(14);
        $sheet->getColumnDimension('B')->setWidth(40);
        $sheet->getColumnDimension('C')->setWidth(16);
        $sheet->getColumnDimension('D')->setWidth(34);
    }

    /** @param list<string> $titles */
    private function header(Worksheet $sheet, array $titles): void
    {
        foreach ($titles as $i => $title) {
            $sheet->setCellValue([$i + 1, 1], $title);
        }

        $sheet->getStyle([1, 1, count($titles), 1])->getFont()->setBold(true);
        $sheet->freezePane('A2');
        // the date column keeps the date format for the rows the user adds
        $sheet->getStyle('A2:A500')->getNumberFormat()->setFormatCode('d. m. yyyy');
    }

    private function date(Worksheet $sheet, string $cell, int $year, int $month, int $day): void
    {
        $sheet->setCellValue($cell, Date::formattedPHPToExcel($year, $month, $day));
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('d. m. yyyy');
    }

    /** @param list<string> $values a drop-down list from the hidden sheet „Seznamy“ that still allows other text */
    private function list(Worksheet $sheet, Worksheet $lists, array $values, string $column, string $title): void
    {
        $listColumn = 'A';

        foreach ($values as $i => $value) {
            $lists->setCellValue($listColumn . ($i + 1), $value);
        }

        $validation = new DataValidation();
        $validation->setType(DataValidation::TYPE_LIST)
            ->setErrorStyle(DataValidation::STYLE_INFORMATION)
            ->setAllowBlank(true)
            ->setShowDropDown(true)
            ->setShowErrorMessage(false)
            ->setPromptTitle($title)
            ->setFormula1(sprintf("'Seznamy'!\$%s\$1:\$%s\$%d", $listColumn, $listColumn, count($values)));
        $sheet->setDataValidation($column . '2:' . $column . '500', $validation);
        $sheet->getStyle($column . '2:' . $column . '500')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
    }

    /**
     * @param mixed $items
     * @return list<array<string, mixed>>
     */
    private static function sortedItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $list = array_values(array_filter($items, is_array(...)));
        usort($list, static fn (array $a, array $b): int => (is_numeric($a['pos'] ?? null) ? (float) $a['pos'] : 0) <=> (is_numeric($b['pos'] ?? null) ? (float) $b['pos'] : 0));

        /** @var list<array<string, mixed>> $list */
        return $list;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
