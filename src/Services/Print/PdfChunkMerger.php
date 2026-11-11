<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Print;

use DateTimeImmutable;
use WBoost\Web\Exceptions\PdfStructureNotSupported;

/**
 * Several PDF files → one, on the local disk, in constant memory: every
 * object of every input is copied into the output under a new number — its
 * dictionary rewritten only where it names another object (`12 0 R`), its
 * stream copied byte for byte — and the inputs' page trees are hung under a
 * new root. Nothing is decoded, so fonts, vector pages and pictures arrive
 * exactly as they were written.
 *
 * Why not Gotenberg's merge route (qpdf), which does the same thing: a print
 * export of a calendar with photos at the upload cap is ~560 MB. Sending it
 * there and back needs the whole file twice in Gotenberg's `/tmp` (a 1 GB
 * tmpfs in production) inside one HTTP call bounded by the 25 s client cap
 * and Gotenberg's own 30 s API timeout. Here it is a file copy: ~1 s per
 * 500 MB, no ceiling but the disk. The inputs can be deleted as they are
 * consumed, so the work directory never holds the calendar twice.
 *
 * While it copies the pages it also gives each one its page boxes
 * ({@see PdfPageBoxes::withBoxes()}) — the exact MediaBox / CropBox, TrimBox
 * and BleedBox — and writes ONE Info dictionary; no incremental update is
 * needed afterwards. And it **deduplicates**: a stream object whose bytes an
 * earlier input already brought (the header / background picture that every
 * chunk of a calendar carries) is written once and shared.
 *
 * Like {@see PdfPageBoxes} it reads only what Chromium (Skia/PDF) writes: a
 * classic cross-reference table, generation-0 objects, direct stream
 * lengths irrelevant (a stream is never parsed — the object is copied up to
 * the next object's offset). Anything else throws
 * {@see PdfStructureNotSupported} and the caller falls back to Gotenberg.
 */
final class PdfChunkMerger
{
    /** How much of an object is read to find the end of its dictionary. */
    private const int HEAD_BYTES = 65536;

    /** A dictionary longer than this is not one of ours. */
    private const int MAX_HEAD_BYTES = 8_000_000;

    private const int COPY_BYTES = 1_048_576;

    /**
     * @param list<string> $paths absolute paths, merged in this order
     * @param null|array{width: float, height: float, trimInset: float, bleedInset: float} $boxes page boxes in pt (see {@see PdfPageBoxes::apply()}); null = pages keep theirs
     * @param array{title?: string, creator?: string, producer?: string, createdAt?: DateTimeImmutable} $info
     * @param bool $consume delete each input once it has been copied
     * @param null|callable(int, int): void $progress called after every input with (inputs done, inputs total)
     *
     * @return int the number of pages
     *
     * @throws PdfStructureNotSupported an input is not what this class reads; the target is removed
     */
    public static function merge(array $paths, string $target, null|array $boxes = null, array $info = [], bool $consume = false, null|callable $progress = null): int
    {
        if ($paths === []) {
            throw new PdfStructureNotSupported('There is nothing to merge.');
        }

        $out = @fopen($target, 'wb');

        if ($out === false) {
            throw new PdfStructureNotSupported('The merged PDF cannot be written.');
        }

        try {
            $pages = self::write($out, $paths, $boxes, $info, $consume, $progress);
        } catch (\Throwable $failure) {
            fclose($out);
            @unlink($target);

            throw $failure;
        }

        fclose($out);

        return $pages;
    }

    /**
     * @param resource $out
     * @param list<string> $paths
     * @param null|array{width: float, height: float, trimInset: float, bleedInset: float} $boxes
     * @param array{title?: string, creator?: string, producer?: string, createdAt?: DateTimeImmutable} $info
     * @param null|callable(int, int): void $progress
     */
    private static function write($out, array $paths, null|array $boxes, array $info, bool $consume, null|callable $progress): int
    {
        // binary marker: tells transfer programs the file is not text
        $position = self::put($out, "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n");
        /** @var array<int, int> $written new object number → offset */
        $written = [];
        // 1 = the new root of the page tree, 2 = the catalog, 3 = Info
        $next = 4;
        $roots = [];
        $pageCount = 0;
        /** @var array<string, int> $seen digest of a stream object → the number it was written under */
        $seen = [];

        foreach ($paths as $index => $path) {
            $in = @fopen($path, 'rb');

            if ($in === false) {
                throw new PdfStructureNotSupported('A PDF chunk cannot be read.');
            }

            try {
                $structure = PdfPageBoxes::structure($in);
                $offsets = $structure['offsets'];

                $catalog = PdfPageBoxes::object($in, $offsets, $structure['root']);

                if (preg_match('~/Pages\s+(\d+)\s+0\s+R~', $catalog, $pagesRef) !== 1) {
                    throw new PdfStructureNotSupported('The PDF catalog has no /Pages.');
                }

                $chunkRoot = (int) $pagesRef[1];
                $pageNumbers = [];
                self::collectPages($in, $offsets, $chunkRoot, $pageNumbers, 0);

                // The chunk's catalog and Info are not carried over; everything else gets a new number.
                $skip = [$structure['root'] => true];

                if ($structure['info'] !== null) {
                    $skip[$structure['info']] = true;
                }

                asort($offsets);
                $numbers = array_keys($offsets);
                $starts = array_values($offsets);
                $lengths = [];

                foreach ($numbers as $i => $number) {
                    $lengths[$number] = ($starts[$i + 1] ?? $structure['xrefOffset']) - $starts[$i];

                    if ($lengths[$number] <= 0) {
                        throw new PdfStructureNotSupported('The PDF objects are not laid out one after another.');
                    }
                }

                // Pass 1 — numbers. A stream object whose bytes an earlier input already
                // brought (the header / background picture every chunk carries, its colour
                // profile, a font file) is not copied again: it takes the number of the
                // first copy.
                $map = [];
                $duplicates = [];

                foreach ($numbers as $number) {
                    if (isset($skip[$number])) {
                        continue;
                    }

                    $digest = isset($pageNumbers[$number]) || $number === $chunkRoot
                        ? null
                        : self::streamDigest($in, $offsets[$number], $lengths[$number], $number, $map);

                    if ($digest !== null && isset($seen[$digest])) {
                        $map[$number] = $seen[$digest];
                        $duplicates[$number] = true;

                        continue;
                    }

                    $map[$number] = $next++;

                    if ($digest !== null) {
                        $seen[$digest] = $map[$number];
                    }
                }

                // Pass 2 — the copy.
                foreach ($numbers as $number) {
                    if (isset($skip[$number]) || isset($duplicates[$number])) {
                        continue;
                    }

                    $written[$map[$number]] = $position;
                    $position += self::copyObject(
                        $in,
                        $out,
                        $offsets[$number],
                        $lengths[$number],
                        $number,
                        $map,
                        match (true) {
                            $number === $chunkRoot => static fn (string $dictionary): string => '<< /Parent 1 0 R ' . ltrim(substr($dictionary, 2)),
                            isset($pageNumbers[$number]) && $boxes !== null => static fn (string $dictionary): string => PdfPageBoxes::withBoxes($dictionary, $boxes['width'], $boxes['height'], $boxes['trimInset'], $boxes['bleedInset']),
                            default => null,
                        },
                    );
                }

                $roots[] = $map[$chunkRoot];
                $pageCount += count($pageNumbers);
            } finally {
                fclose($in);
            }

            if ($consume) {
                @unlink($path);
            }

            if ($progress !== null) {
                $progress($index + 1, count($paths));
            }
        }

        $written[1] = $position;
        $position += self::put($out, sprintf(
            "1 0 obj\n<< /Type /Pages /Kids [ %s ] /Count %d >>\nendobj\n",
            implode(' ', array_map(static fn (int $number): string => $number . ' 0 R', $roots)),
            $pageCount,
        ));
        $written[2] = $position;
        $position += self::put($out, "2 0 obj\n<< /Type /Catalog /Pages 1 0 R >>\nendobj\n");
        $written[3] = $position;
        $position += self::put($out, sprintf("3 0 obj\n%s\nendobj\n", PdfPageBoxes::info($info)));

        ksort($written);
        $xref = sprintf("xref\n0 %d\n0000000000 65535 f \n", $next);

        for ($number = 1; $number < $next; ++$number) {
            if (!isset($written[$number])) {
                throw new PdfStructureNotSupported('The merged PDF is missing an object.');
            }

            $xref .= sprintf("%010d 00000 n \n", $written[$number]);
        }

        $id = bin2hex(random_bytes(16));
        self::put($out, $xref . sprintf(
            "trailer\n<< /Size %d /Root 2 0 R /Info 3 0 R /ID [ <%s> <%s> ] >>\nstartxref\n%d\n%%%%EOF\n",
            $next,
            $id,
            $id,
            $position,
        ));

        return $pageCount;
    }

    /**
     * One object from `$in` to `$out` under its new number. Only the part in
     * front of a stream is read into memory and rewritten; the rest is a raw
     * copy.
     *
     * @param resource $in
     * @param resource $out
     * @param array<int, int> $map old object number → new
     * @param null|\Closure(string): string $rewrite applied to the (already renumbered) dictionary
     *
     * @return int bytes written
     */
    private static function copyObject($in, $out, int $offset, int $length, int $number, array $map, null|\Closure $rewrite): int
    {
        [$value, $rest, $read] = self::head($in, $offset, $length, $number);
        $value = self::renumber($value, $map);

        if ($rewrite !== null) {
            $dictionary = trim($value);

            if (!str_starts_with($dictionary, '<<')) {
                throw new PdfStructureNotSupported(sprintf('The PDF object %d is not a dictionary.', $number));
            }

            $value = $rewrite($dictionary);
        }

        // what follows the value — ` stream … endstream endobj`, or just `endobj` — goes out as it is
        $bytes = self::put($out, sprintf("%d 0 obj\n%s", $map[$number], ltrim($value)));
        $bytes += self::put($out, $rest);
        $left = $length - $read;

        while ($left > 0) {
            $chunk = fread($in, min(self::COPY_BYTES, $left));

            if ($chunk === false || $chunk === '') {
                throw new PdfStructureNotSupported(sprintf('The PDF object %d ends before its length.', $number));
            }

            $bytes += self::put($out, $chunk);
            $left -= strlen($chunk);
        }

        return $bytes;
    }

    /**
     * A digest of a STREAM object as it would be written — its dictionary
     * with the new numbers, then its bytes — or null when the object has no
     * stream or names an object that has no new number yet (a forward
     * reference: such an object is simply copied). Two objects with the same
     * digest are the same value, so one copy serves both.
     *
     * @param resource $in
     * @param array<int, int> $map the numbers assigned so far
     */
    private static function streamDigest($in, int $offset, int $length, int $number, array $map): null|string
    {
        [$value, $rest, $read] = self::head($in, $offset, $length, $number);

        if (preg_match('/^\s*stream\b/', $rest) !== 1 || preg_match('~/Type\s*/Pages?(?![A-Za-z])~', $value) === 1) {
            return null;
        }

        try {
            $value = self::renumber($value, $map);
        } catch (PdfStructureNotSupported) {
            return null;
        }

        $hash = hash_init('xxh128');
        hash_update($hash, ltrim($value));
        hash_update($hash, $rest);
        $left = $length - $read;

        while ($left > 0) {
            $chunk = fread($in, min(self::COPY_BYTES, $left));

            if ($chunk === false || $chunk === '') {
                return null;
            }

            hash_update($hash, $chunk);
            $left -= strlen($chunk);
        }

        return hash_final($hash) . ':' . $length;
    }

    /**
     * The front of an object: its value (the dictionary, or whatever else it
     * is), the bytes read beyond it, and how many bytes of the object were
     * read in all — the file is left positioned right after them.
     *
     * @param resource $in
     *
     * @return array{string, string, int}
     */
    private static function head($in, int $offset, int $length, int $number): array
    {
        fseek($in, $offset);
        $head = '';
        $bodyStart = null;
        $bodyEnd = null;

        do {
            $want = min(self::HEAD_BYTES, $length - strlen($head));
            $chunk = $want > 0 ? fread($in, $want) : '';

            if ($chunk === false || $chunk === '') {
                break;
            }

            $head .= $chunk;

            if ($bodyStart === null) {
                if (preg_match('/^\s*(\d+)\s+0\s+obj/', $head, $header) !== 1 || (int) $header[1] !== $number) {
                    throw new PdfStructureNotSupported(sprintf('The PDF object %d is not where the cross-reference table says.', $number));
                }

                $bodyStart = strlen($header[0]);
            }

            $bodyEnd = self::valueEnd($head, $bodyStart);
        } while ($bodyEnd === null && strlen($head) < min($length, self::MAX_HEAD_BYTES));

        if ($bodyStart === null || $bodyEnd === null) {
            throw new PdfStructureNotSupported(sprintf('The PDF object %d is unreadable.', $number));
        }

        return [substr($head, $bodyStart, $bodyEnd - $bodyStart), substr($head, $bodyEnd), strlen($head)];
    }

    /**
     * Where the object's value ends (exclusive) — the end of its dictionary,
     * or for another kind of value the `endobj` keyword. Null = not within
     * the bytes at hand. Strings are skipped, so nothing inside a string is
     * ever taken for syntax.
     */
    private static function valueEnd(string $bytes, int $from): null|int
    {
        $length = strlen($bytes);
        $i = $from;

        while ($i < $length && ctype_space($bytes[$i])) {
            ++$i;
        }

        if ($i + 1 >= $length) {
            return null;
        }

        if ($bytes[$i] !== '<' || $bytes[$i + 1] !== '<') {
            $end = strpos($bytes, 'endobj', $i);

            return $end === false ? null : $end;
        }

        $depth = 0;

        while ($i < $length) {
            $char = $bytes[$i];

            if ($char === '(') {
                $i = self::skipString($bytes, $i);

                if ($i === null) {
                    return null;
                }

                continue;
            }

            if ($char === '<') {
                if ($i + 1 >= $length) {
                    return null;
                }

                if ($bytes[$i + 1] === '<') {
                    ++$depth;
                    $i += 2;

                    continue;
                }

                $close = strpos($bytes, '>', $i);

                if ($close === false) {
                    return null;
                }

                $i = $close + 1;

                continue;
            }

            if ($char === '>') {
                if ($i + 1 >= $length) {
                    return null;
                }

                if ($bytes[$i + 1] === '>') {
                    --$depth;
                    $i += 2;

                    if ($depth === 0) {
                        return $i;
                    }

                    continue;
                }
            }

            ++$i;
        }

        return null;
    }

    /** The index after the literal string that starts at `$at`, or null when it does not end here. */
    private static function skipString(string $bytes, int $at): null|int
    {
        $length = strlen($bytes);
        $depth = 0;

        for ($i = $at; $i < $length; ++$i) {
            $char = $bytes[$i];

            if ($char === '\\') {
                ++$i;
            } elseif ($char === '(') {
                ++$depth;
            } elseif ($char === ')') {
                --$depth;

                if ($depth === 0) {
                    return $i + 1;
                }
            }
        }

        return null;
    }

    /**
     * Every indirect reference of a value (`12 0 R`) with its new number —
     * outside strings only.
     *
     * @param array<int, int> $map
     */
    private static function renumber(string $value, array $map): string
    {
        $out = '';
        $length = strlen($value);
        $from = 0;
        $i = 0;

        $replace = static function (string $part) use ($map): string {
            return (string) preg_replace_callback(
                '/(?<![\w.+-])(\d+)(\s+)0(\s+)R(?![\w])/',
                static function (array $match) use ($map): string {
                    $old = (int) $match[1];

                    if (!isset($map[$old])) {
                        throw new PdfStructureNotSupported(sprintf('A PDF object refers to object %d, which is not carried over.', $old));
                    }

                    return $map[$old] . $match[2] . '0' . $match[3] . 'R';
                },
                $part,
            );
        };

        while ($i < $length) {
            $char = $value[$i];
            $end = null;

            if ($char === '(') {
                $end = self::skipString($value, $i);
            } elseif ($char === '<' && ($value[$i + 1] ?? '') !== '<') {
                $close = strpos($value, '>', $i);
                $end = $close === false ? null : $close + 1;
            } elseif ($char === '<') {
                ++$i;
            }

            if ($end === null) {
                ++$i;

                continue;
            }

            $out .= $replace(substr($value, $from, $i - $from)) . substr($value, $i, $end - $i);
            $from = $i = $end;
        }

        return $out . $replace(substr($value, $from));
    }

    /**
     * @param resource $file
     * @param array<int, int> $offsets
     * @param array<int, true> $pages page object numbers
     */
    private static function collectPages($file, array $offsets, int $number, array &$pages, int $depth): void
    {
        if ($depth > 32 || isset($pages[$number])) {
            throw new PdfStructureNotSupported('The PDF page tree is too deep or loops.');
        }

        $dictionary = PdfPageBoxes::object($file, $offsets, $number);

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

        $pages[$number] = true;
    }

    /**
     * @param resource $out
     */
    private static function put($out, string $bytes): int
    {
        $length = strlen($bytes);

        if ($length > 0 && fwrite($out, $bytes) !== $length) {
            throw new PdfStructureNotSupported('The merged PDF could not be written (disk full?).');
        }

        return $length;
    }
}
