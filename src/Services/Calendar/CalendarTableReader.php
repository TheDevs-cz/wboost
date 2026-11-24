<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Ods;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use WBoost\Web\Exceptions\InvalidCalendarChange;

/**
 * „Nahrát z Excelu“ (K10b, docs/calendar/excel-import-plan.md): reads a
 * spreadsheet into rows of cell strings. The browser maps and previews the
 * rows (`model/import.js`) and writes ordinary content ops — nothing here
 * stores the file or touches the calendar.
 *
 * - Formats: `.xlsx`, `.xls`, `.ods`, `.csv` / `.tsv` / `.txt`. The type comes
 *   from the extension; without a known one the content decides among the
 *   three binary formats. A CSV is UTF-8 (± BOM) when it is valid UTF-8, else
 *   Windows-1250 — what Czech Excel writes as „CSV (oddělený středníkem)“;
 *   the separator is the most frequent of `;`, TAB, `,` in the first lines.
 * - A cell is a string: a DATE cell (a number with a date format) becomes
 *   ISO `YYYY-MM-DD`, so the browser never guesses what Excel already knew;
 *   other numbers are printed in their number format; formulas give their
 *   cached result (nothing is evaluated).
 * - Row index = the sheet's row − 1 (empty rows stay as `[]`, so the preview
 *   can name rows as the file does); trailing empty cells and rows are cut.
 * - Limits: {@see MAX_SHEETS} sheets, {@see MAX_ROWS} rows × {@see MAX_COLUMNS}
 *   columns each — more is cut and reported as `truncated`.
 */
final class CalendarTableReader
{
    public const int MAX_SHEETS = 10;

    public const int MAX_ROWS = 2000;

    public const int MAX_COLUMNS = 60;

    public const int MAX_CELL_LENGTH = 300;

    public const string UNREADABLE = 'Soubor se nepodařilo přečíst — nahrajte tabulku ve formátu xlsx, xls, ods nebo csv.';

    public const string EMPTY = 'V souboru není žádná tabulka s daty.';

    private const array EXTENSIONS = [
        'xlsx' => 'xlsx', 'xlsm' => 'xlsx', 'xls' => 'xls', 'ods' => 'ods',
        'csv' => 'csv', 'tsv' => 'csv', 'txt' => 'csv',
    ];

    /**
     * @return array{sheets: list<array{name: string, rows: list<list<string>>}>, truncated: bool}
     *
     * @throws InvalidCalendarChange with the Czech sentence for the user
     */
    public function read(string $path, string $fileName): array
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $type = self::EXTENSIONS[$extension] ?? $this->sniff($path);

        if ($type === null) {
            throw new InvalidCalendarChange(self::UNREADABLE);
        }

        $truncated = false;

        try {
            $reader = $this->reader($type, $path);
            $reader->setReadEmptyCells(false);
            $filter = new class implements IReadFilter {
                public bool $cut = false;

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    if ($row > CalendarTableReader::MAX_ROWS || Coordinate::columnIndexFromString($columnAddress) > CalendarTableReader::MAX_COLUMNS) {
                        $this->cut = true;

                        return false;
                    }

                    return true;
                }
            };
            $reader->setReadFilter($filter);
            $spreadsheet = $reader->load($path);
            $truncated = $filter->cut;
        } catch (InvalidCalendarChange $invalid) {
            throw $invalid;
        } catch (\Throwable) {
            throw new InvalidCalendarChange(self::UNREADABLE);
        }

        $sheets = [];

        foreach ($spreadsheet->getWorksheetIterator() as $index => $sheet) {
            if ($index >= self::MAX_SHEETS) {
                $truncated = true;

                break;
            }

            $rows = [];

            foreach ($sheet->getRowIterator(1, min(self::MAX_ROWS, max(1, $sheet->getHighestDataRow()))) as $row) {
                $cells = [];

                foreach ($row->getCellIterator('A', Coordinate::stringFromColumnIndex(self::MAX_COLUMNS), true) as $cell) {
                    $cells[Coordinate::columnIndexFromString($cell->getColumn()) - 1] = self::text($cell);
                }

                $line = [];

                if ($cells !== []) {
                    for ($c = 0, $last = max(array_keys($cells)); $c <= $last; ++$c) {
                        $line[] = $cells[$c] ?? '';
                    }
                }

                while ($line !== [] && end($line) === '') {
                    array_pop($line);
                }

                $rows[$row->getRowIndex() - 1] = $line;
            }

            $list = [];
            $last = -1;

            foreach ($rows as $r => $line) {
                if ($line !== []) {
                    $last = max($last, $r);
                }
            }

            for ($r = 0; $r <= $last; ++$r) {
                $list[] = $rows[$r] ?? [];
            }

            if ($list !== []) {
                $sheets[] = ['name' => CalendarText::singleLine(mb_substr($sheet->getTitle(), 0, 60)), 'rows' => $list];
            }
        }

        $spreadsheet->disconnectWorksheets();

        if ($sheets === []) {
            throw new InvalidCalendarChange(self::EMPTY);
        }

        return ['sheets' => $sheets, 'truncated' => $truncated];
    }

    private function reader(string $type, string $path): IReader
    {
        if ($type !== 'csv') {
            return match ($type) {
                'xlsx' => new Xlsx(),
                'xls' => new Xls(),
                default => new Ods(),
            };
        }

        $head = (string) file_get_contents($path, false, null, 0, 65536);

        if (str_contains($head, "\0")) {
            throw new InvalidCalendarChange(self::UNREADABLE);
        }

        $body = str_starts_with($head, "\xEF\xBB\xBF") ? substr($head, 3) : $head;
        $utf8 = self::isUtf8($body, strlen($head) === 65536);

        $reader = new Csv();
        $reader->setInputEncoding($utf8 ? 'UTF-8' : 'CP1250');
        $reader->setDelimiter(self::delimiter($body));
        $reader->setEnclosure('"');

        return $reader;
    }

    /** Valid UTF-8 — a head cut at the read limit may end inside a character (up to 3 bytes). */
    private static function isUtf8(string $text, bool $cut): bool
    {
        for ($drop = 0; $drop <= ($cut ? 3 : 0); ++$drop) {
            if (mb_check_encoding(substr($text, 0, strlen($text) - $drop), 'UTF-8')) {
                return true;
            }
        }

        return false;
    }

    /** The binary formats by content, for a file without a known extension. */
    private function sniff(string $path): null|string
    {
        foreach (['xlsx' => new Xlsx(), 'ods' => new Ods(), 'xls' => new Xls()] as $type => $reader) {
            try {
                if ($reader->canRead($path)) {
                    return $type;
                }
            } catch (\Throwable) {
                // not this one
            }
        }

        return null;
    }

    /** `;`, TAB or `,` — whichever the first lines hold most often (`;` wins a tie: Czech Excel). */
    public static function delimiter(string $text): string
    {
        $lines = array_slice(preg_split('/\r\n|\r|\n/', $text) ?: [], 0, 20);
        $sample = implode("\n", $lines);
        $best = ';';
        $count = substr_count($sample, ';');

        foreach (["\t", ','] as $candidate) {
            $n = substr_count($sample, $candidate);

            if ($n > $count) {
                $best = $candidate;
                $count = $n;
            }
        }

        return $best;
    }

    private static function text(Cell $cell): string
    {
        $value = $cell->getValue();

        if ($cell->isFormula()) {
            $value = $cell->getOldCalculatedValue();
        }

        if ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            $value = $value->getPlainText();
        }

        if (is_int($value) || is_float($value)) {
            if (Date::isDateTime($cell, $value)) {
                try {
                    return Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
                } catch (\Throwable) {
                    return '';
                }
            }

            $format = $cell->getStyle()->getNumberFormat()->getFormatCode() ?? NumberFormat::FORMAT_GENERAL;
            $value = NumberFormat::toFormattedString($value, $format);
        }

        if (is_bool($value)) {
            $value = $value ? 'ano' : 'ne';
        }

        if (!is_string($value)) {
            return '';
        }

        return mb_substr(trim(CalendarText::singleLine($value)), 0, self::MAX_CELL_LENGTH);
    }
}
