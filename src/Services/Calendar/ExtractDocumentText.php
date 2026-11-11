<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use WBoost\Web\Exceptions\InvalidCalendarChange;

/**
 * The plain text of an uploaded document for a "Text z Wordu" special page:
 * `.docx` (one line per paragraph) or `.txt`. Paragraphs joined by `\n`, at
 * most 6000 characters — the calendar typesets it in its own style.
 */
readonly final class ExtractDocumentText
{
    public const int MAX_LENGTH = 6000;
    public const string UNSUPPORTED = 'Nahrajte dokument ve formátu DOCX nebo TXT.';

    /**
     * A .docx is a zip: 10 MB of archive can unpack to gigabytes of XML. The
     * size the archive DECLARES for `word/document.xml` is checked before a
     * byte of it is inflated; a real text of a calendar page is a few kB.
     */
    public const int MAX_DOCUMENT_XML_BYTES = 5_000_000;
    public const string TOO_LARGE = 'Dokument je příliš rozsáhlý. Nahrajte kratší text.';

    /**
     * @throws InvalidCalendarChange
     */
    public function extract(string $contents, string $fileName): string
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $text = match (true) {
            str_starts_with($contents, "PK\x03\x04") => $this->docx($contents),
            $extension === 'txt' => self::plain($contents),
            default => throw new InvalidCalendarChange(self::UNSUPPORTED),
        };

        $paragraphs = [];

        // Whatever the document held, the text goes back into a save.
        foreach (preg_split('/\R/u', CalendarText::clean($text)) ?: [] as $line) {
            $line = trim((string) preg_replace('/[^\S\n]+/u', ' ', $line));

            if ($line !== '') {
                $paragraphs[] = $line;
            }
        }

        return mb_substr(implode("\n", $paragraphs), 0, self::MAX_LENGTH);
    }

    /**
     * @throws InvalidCalendarChange
     */
    private function docx(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'calendar-docx-');

        if ($file === false) {
            throw new InvalidCalendarChange(self::UNSUPPORTED);
        }

        try {
            file_put_contents($file, $contents);
            $zip = new \ZipArchive();

            if ($zip->open($file, \ZipArchive::RDONLY) !== true) {
                throw new InvalidCalendarChange(self::UNSUPPORTED);
            }

            $entry = $zip->statName('word/document.xml');

            if ($entry === false) {
                $zip->close();

                throw new InvalidCalendarChange(self::UNSUPPORTED);
            }

            if ($entry['size'] > self::MAX_DOCUMENT_XML_BYTES) {
                $zip->close();

                throw new InvalidCalendarChange(self::TOO_LARGE);
            }

            // `len` bounds the inflate as well, should the declared size lie.
            $xml = $zip->getFromName('word/document.xml', self::MAX_DOCUMENT_XML_BYTES + 1);
            $zip->close();

            if (is_string($xml) && strlen($xml) > self::MAX_DOCUMENT_XML_BYTES) {
                throw new InvalidCalendarChange(self::TOO_LARGE);
            }
        } finally {
            @unlink($file);
        }

        if (!is_string($xml) || $xml === '') {
            throw new InvalidCalendarChange(self::UNSUPPORTED);
        }

        $reader = new \XMLReader();

        if (!@$reader->XML($xml, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new InvalidCalendarChange(self::UNSUPPORTED);
        }

        $lines = [];
        $line = '';

        while (@$reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT) {
                if ($reader->name === 'w:t') {
                    $line .= $reader->readString();
                } elseif ($reader->name === 'w:tab') {
                    $line .= ' ';
                } elseif ($reader->name === 'w:br') {
                    $lines[] = $line;
                    $line = '';
                }
            } elseif ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->name === 'w:p') {
                $lines[] = $line;
                $line = '';
            }
        }

        $reader->close();

        return implode("\n", $lines);
    }

    /**
     * @throws InvalidCalendarChange
     */
    private static function plain(string $contents): string
    {
        // Strip a UTF-8 byte order mark; Windows Notepad still writes ANSI.
        $contents = (string) preg_replace('/^\xEF\xBB\xBF/', '', $contents);

        if (!mb_check_encoding($contents, 'UTF-8')) {
            $converted = @iconv('Windows-1250', 'UTF-8//IGNORE', $contents);

            if ($converted === false) {
                throw new InvalidCalendarChange(self::UNSUPPORTED);
            }

            $contents = $converted;
        }

        if (str_contains($contents, "\0")) {
            throw new InvalidCalendarChange(self::UNSUPPORTED);
        }

        return $contents;
    }
}
