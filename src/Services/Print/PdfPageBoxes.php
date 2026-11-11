<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Print;

use DateTimeImmutable;
use WBoost\Web\Exceptions\PdfStructureNotSupported;

/**
 * Gives every page of an existing PDF its page boxes — an exact MediaBox /
 * CropBox, a TrimBox and a BleedBox — and the file an Info dictionary, by
 * APPENDING an incremental update (PDF 32000-1 §7.5.6): new versions of the
 * page objects, a cross-reference section for them and a trailer that points
 * back at the original one. Not one byte of the existing file is touched, so
 * whatever it holds (vector text, embedded fonts, pictures) stays exactly as
 * it was written.
 *
 * Why it exists: the calendar PDF export prints pages in Chromium, which
 * (a) knows no TrimBox / BleedBox and (b) rounds the page size to whole
 * points. The export therefore prints on a page a fraction LARGER than asked
 * and this class cuts the MediaBox back to the exact size, anchored at the
 * top-left corner where Chromium places the content.
 *
 * Deliberately narrow: it reads the classic cross-reference TABLE of a file
 * without earlier updates and without encryption — what Skia/PDF (Chromium)
 * and qpdf (Gotenberg's merge) write. Anything else throws
 * {@see PdfStructureNotSupported} before the file is modified; the caller
 * then ships the file as it is.
 */
final class PdfPageBoxes
{
    private const int TAIL_BYTES = 2048;

    private const int OBJECT_READ_BYTES = 65536;

    /** The largest dictionary object read (a page or a page-tree node; never a stream). */
    private const int MAX_OBJECT_BYTES = 4_000_000;

    private const float TOLERANCE = 0.02;

    /**
     * @param float $width the exact page width in pt
     * @param float $height the exact page height in pt
     * @param float $trimInset distance of the TrimBox from the page edge, pt (0 = the page is the trim)
     * @param float $bleedInset distance of the BleedBox from the page edge, pt
     * @param array{title?: string, creator?: string, producer?: string, createdAt?: DateTimeImmutable} $info
     *
     * @return int the number of pages
     *
     * @throws PdfStructureNotSupported the file is not what this class reads; nothing was written
     */
    public static function apply(string $path, float $width, float $height, float $trimInset, float $bleedInset, array $info = []): int
    {
        $file = @fopen($path, 'r+b');

        if ($file === false) {
            throw new PdfStructureNotSupported('The PDF file cannot be opened for writing.');
        }

        try {
            return self::update($file, $width, $height, $trimInset, $bleedInset, $info);
        } finally {
            fclose($file);
        }
    }

    /**
     * @param resource $file
     * @param array{title?: string, creator?: string, producer?: string, createdAt?: DateTimeImmutable} $info
     */
    private static function update($file, float $width, float $height, float $trimInset, float $bleedInset, array $info): int
    {
        ['size' => $size, 'xrefOffset' => $xrefOffset, 'offsets' => $offsets, 'root' => $rootNumber, 'objectCount' => $objectCount, 'id' => $documentId] = self::structure($file);

        $catalog = self::object($file, $offsets, $rootNumber);

        if (preg_match('~/Pages\s+(\d+)\s+0\s+R~', $catalog, $pagesRef) !== 1) {
            throw new PdfStructureNotSupported('The PDF catalog has no /Pages.');
        }

        $pages = [];
        self::collectPages($file, $offsets, (int) $pagesRef[1], $pages, 0);

        if ($pages === []) {
            throw new PdfStructureNotSupported('The PDF has no pages.');
        }

        // Everything is read and understood — only now is anything written.
        $objects = [];

        foreach ($pages as $number => $dictionary) {
            $objects[$number] = self::withBoxes($dictionary, $width, $height, $trimInset, $bleedInset);
        }

        $nextNumber = $objectCount;
        $infoNumber = $nextNumber++;
        $objects[$infoNumber] = self::info($info);
        ksort($objects);

        fseek($file, 0, SEEK_END);
        $position = $size;
        $written = [];
        $out = "\n";

        foreach ($objects as $number => $body) {
            $written[$number] = $position + strlen($out);
            $out .= sprintf("%d 0 obj\n%s\nendobj\n", $number, $body);
        }

        $newXrefOffset = $position + strlen($out);
        $out .= "xref\n";
        $numbers = array_keys($written);

        for ($i = 0, $count = count($numbers); $i < $count;) {
            $run = 1;

            while ($i + $run < $count && $numbers[$i + $run] === $numbers[$i] + $run) {
                ++$run;
            }

            $out .= sprintf("%d %d\n", $numbers[$i], $run);

            for ($k = 0; $k < $run; ++$k) {
                $out .= sprintf("%010d 00000 n \n", $written[$numbers[$i + $k]]);
            }

            $i += $run;
        }

        $out .= sprintf(
            "trailer\n<< /Size %d /Root %d 0 R /Info %d 0 R /Prev %d%s >>\nstartxref\n%d\n%%%%EOF\n",
            $nextNumber,
            $rootNumber,
            $infoNumber,
            $xrefOffset,
            $documentId !== null ? ' /ID ' . $documentId : '',
            $newXrefOffset,
        );

        if (fwrite($file, $out) !== strlen($out)) {
            // A partial append leaves the old startxref unreachable from the end; cut it off again.
            ftruncate($file, max(0, $size));

            throw new PdfStructureNotSupported('The PDF update could not be written.');
        }

        return count($pages);
    }

    /**
     * The skeleton of a file this class (and {@see PdfChunkMerger}) reads:
     * one classic cross-reference table, no earlier update, no encryption.
     *
     * @param resource $file
     *
     * @return array{size: int, xrefOffset: int, offsets: array<int, int>, root: int, objectCount: int, info: null|int, id: null|string}
     *
     * @throws PdfStructureNotSupported
     */
    public static function structure($file): array
    {
        $size = (int) (fstat($file)['size'] ?? 0);

        if ($size < 64) {
            throw new PdfStructureNotSupported('The PDF file is empty.');
        }

        fseek($file, max(0, $size - self::TAIL_BYTES));
        $tail = (string) stream_get_contents($file);

        if (preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/', $tail, $match) !== 1) {
            throw new PdfStructureNotSupported('The PDF file has no startxref.');
        }

        $xrefOffset = (int) $match[1];

        if ($xrefOffset <= 0 || $xrefOffset >= $size) {
            throw new PdfStructureNotSupported('The PDF cross-reference offset is out of range.');
        }

        fseek($file, $xrefOffset);
        $xref = (string) stream_get_contents($file);

        if (!str_starts_with($xref, 'xref')) {
            throw new PdfStructureNotSupported('The PDF uses a cross-reference stream, not a table.');
        }

        $trailerAt = strpos($xref, 'trailer');

        if ($trailerAt === false) {
            throw new PdfStructureNotSupported('The PDF has no trailer.');
        }

        $offsets = self::offsets(substr($xref, 4, $trailerAt - 4));
        $trailer = substr($xref, $trailerAt + 7);

        if (preg_match('~/(Prev|Encrypt|XRefStm)\b~', $trailer, $unsupported) === 1) {
            throw new PdfStructureNotSupported(sprintf('The PDF trailer has /%s.', $unsupported[1]));
        }

        if (preg_match('~/Root\s+(\d+)\s+0\s+R~', $trailer, $root) !== 1 || preg_match('~/Size\s+(\d+)~', $trailer, $sizeEntry) !== 1) {
            throw new PdfStructureNotSupported('The PDF trailer has no /Root or /Size.');
        }

        return [
            'size' => $size,
            'xrefOffset' => $xrefOffset,
            'offsets' => $offsets,
            'root' => (int) $root[1],
            'objectCount' => (int) $sizeEntry[1],
            'info' => preg_match('~/Info\s+(\d+)\s+0\s+R~', $trailer, $info) === 1 ? (int) $info[1] : null,
            'id' => preg_match('~/ID\s*(\[\s*<[0-9A-Fa-f]*>\s*<[0-9A-Fa-f]*>\s*\])~', $trailer, $id) === 1 ? $id[1] : null,
        ];
    }

    /**
     * Object number → byte offset, from the body of a cross-reference table.
     *
     * @return array<int, int>
     */
    private static function offsets(string $table): array
    {
        $offsets = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($table));

        if ($lines === false) {
            throw new PdfStructureNotSupported('The PDF cross-reference table is unreadable.');
        }

        $number = null;
        $left = 0;

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if ($left === 0) {
                if (preg_match('/^(\d+)\s+(\d+)$/', $line, $head) !== 1) {
                    throw new PdfStructureNotSupported('The PDF cross-reference table has an unexpected subsection head.');
                }

                $number = (int) $head[1];
                $left = (int) $head[2];

                continue;
            }

            if ($number === null || preg_match('/^(\d{10})\s+(\d{5})\s+([nf])$/', $line, $entry) !== 1) {
                throw new PdfStructureNotSupported('The PDF cross-reference table has an unexpected entry.');
            }

            if ($entry[3] === 'n' && (int) $entry[2] === 0) {
                $offsets[$number] = (int) $entry[1];
            }

            ++$number;
            --$left;
        }

        return $offsets;
    }

    /**
     * The body of a dictionary object (between `obj` and `endobj`).
     *
     * @param resource $file
     * @param array<int, int> $offsets
     */
    public static function object($file, array $offsets, int $number): string
    {
        if (!isset($offsets[$number])) {
            throw new PdfStructureNotSupported(sprintf('The PDF object %d is not in the cross-reference table.', $number));
        }

        fseek($file, $offsets[$number]);
        $data = '';

        do {
            $chunk = fread($file, self::OBJECT_READ_BYTES);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $data .= $chunk;
            $end = strpos($data, 'endobj');
        } while ($end === false && strlen($data) < self::MAX_OBJECT_BYTES);

        $end = strpos($data, 'endobj');

        if ($end === false || preg_match('/^\s*(\d+)\s+0\s+obj\b/', $data, $head) !== 1 || (int) $head[1] !== $number) {
            throw new PdfStructureNotSupported(sprintf('The PDF object %d is unreadable.', $number));
        }

        $body = trim(substr($data, strlen($head[0]), $end - strlen($head[0])));

        if (!str_starts_with($body, '<<') || !str_ends_with($body, '>>')) {
            throw new PdfStructureNotSupported(sprintf('The PDF object %d is not a plain dictionary.', $number));
        }

        return $body;
    }

    /**
     * @param resource $file
     * @param array<int, int> $offsets
     * @param array<int, string> $pages object number → page dictionary, in document order
     */
    private static function collectPages($file, array $offsets, int $number, array &$pages, int $depth): void
    {
        if ($depth > 32 || isset($pages[$number])) {
            throw new PdfStructureNotSupported('The PDF page tree is too deep or loops.');
        }

        $dictionary = self::object($file, $offsets, $number);

        if (preg_match('~/Type\s*/Pages(?![A-Za-z])~', $dictionary) === 1) {
            if (preg_match('~/Kids\s*\[([^\]]*)\]~', $dictionary, $kids) !== 1) {
                throw new PdfStructureNotSupported('A PDF page-tree node has no /Kids.');
            }

            preg_match_all('~(\d+)\s+0\s+R~', $kids[1], $references);

            foreach ($references[1] as $kid) {
                self::collectPages($file, $offsets, (int) $kid, $pages, $depth + 1);
            }

            return;
        }

        if (preg_match('~/Type\s*/Page(?![A-Za-z])~', $dictionary) !== 1) {
            throw new PdfStructureNotSupported(sprintf('The PDF object %d is neither a page nor a page-tree node.', $number));
        }

        $pages[$number] = $dictionary;
    }

    /**
     * The page dictionary with its boxes replaced. Chromium anchors the
     * content at the TOP-left corner of the page it printed on, so the exact
     * page is measured down and right from there.
     */
    public static function withBoxes(string $dictionary, float $width, float $height, float $trimInset, float $bleedInset): string
    {
        $number = '([-+]?\d*\.?\d+)';

        if (preg_match('~/MediaBox\s*\[\s*' . $number . '\s+' . $number . '\s+' . $number . '\s+' . $number . '\s*\]~', $dictionary, $media) !== 1) {
            throw new PdfStructureNotSupported('A PDF page has no MediaBox of its own.');
        }

        if (preg_match('~/Rotate\s+[1-9]~', $dictionary) === 1 || preg_match('~/UserUnit\b~', $dictionary) === 1) {
            throw new PdfStructureNotSupported('A PDF page is rotated or scaled.');
        }

        $left = (float) $media[1];
        $top = (float) $media[4];
        $actualWidth = (float) $media[3] - $left;
        $actualHeight = $top - (float) $media[2];

        if ($width > $actualWidth + self::TOLERANCE || $height > $actualHeight + self::TOLERANCE) {
            throw new PdfStructureNotSupported(sprintf(
                'A PDF page is %s × %s pt — smaller than the %s × %s pt asked for.',
                PdfObjects::number($actualWidth),
                PdfObjects::number($actualHeight),
                PdfObjects::number($width),
                PdfObjects::number($height),
            ));
        }

        $width = min($width, $actualWidth);
        $height = min($height, $actualHeight);
        $box = static fn (float $inset): string => PdfObjects::box([
            $left + $inset,
            $top - $height + $inset,
            $left + $width - $inset,
            $top - $inset,
        ]);

        $rest = preg_replace('~/(?:Media|Crop|Trim|Bleed|Art)Box\s*\[[^\]]*\]\s*~', '', substr($dictionary, 2));

        if (!is_string($rest)) {
            throw new PdfStructureNotSupported('A PDF page dictionary is unreadable.');
        }

        return sprintf(
            '<< /MediaBox %1$s /CropBox %1$s /BleedBox %2$s /TrimBox %3$s %4$s',
            $box(0.0),
            $box(max(0.0, $bleedInset)),
            $box(max(0.0, $trimInset)),
            ltrim($rest),
        );
    }

    /**
     * @param array{title?: string, creator?: string, producer?: string, createdAt?: DateTimeImmutable} $info
     */
    public static function info(array $info): string
    {
        $entries = '';

        foreach (['title' => 'Title', 'creator' => 'Creator', 'producer' => 'Producer'] as $key => $name) {
            if (isset($info[$key]) && $info[$key] !== '') {
                $entries .= sprintf(' /%s %s', $name, PdfObjects::text($info[$key]));
            }
        }

        if (isset($info['createdAt'])) {
            $date = '(' . PdfObjects::date($info['createdAt']) . ')';
            $entries .= sprintf(' /CreationDate %1$s /ModDate %1$s', $date);
        }

        return '<<' . $entries . ' >>';
    }
}
