<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Print;

/**
 * The low-level half of the hand-written PDFs ({@see PdfX1aWriter},
 * {@see ScreenPdfWriter}): an object table with dynamically allocated
 * numbers, the file assembly (header, body, contiguous xref, trailer) and the
 * value serialisers both writers share.
 *
 * Usage: `reserve()` a number when another object must reference it before
 * it is written (the pages tree ↔ its kids), `set()` it later, or `add()` in
 * one go; then `toPdf()`. Numbers run 1…n without gaps, so the xref is one
 * contiguous subsection.
 *
 * Memory: stream data is kept by reference (PHP strings are copy-on-write),
 * and the file is assembled with one `implode()` — a 30-page document holds
 * its encoded page images once plus the final file, never per-append copies.
 */
final class PdfObjects
{
    /** @var array<int, null|string|array{string, string}> number → dictionary, or [dictionary, stream data] */
    private array $objects = [];

    public function reserve(): int
    {
        $number = count($this->objects) + 1;
        $this->objects[$number] = null;

        return $number;
    }

    /**
     * @param string|array{string, string} $object a plain object, or [dictionary, stream data] from {@see stream()}
     */
    public function set(int $number, string|array $object): void
    {
        if (!array_key_exists($number, $this->objects)) {
            throw new \LogicException(sprintf('PDF object %d was not reserved.', $number));
        }

        $this->objects[$number] = $object;
    }

    /**
     * @param string|array{string, string} $object
     */
    public function add(string|array $object): int
    {
        $number = $this->reserve();
        $this->set($number, $object);

        return $number;
    }

    /**
     * A stream object: the dictionary entries (without `<< >>` and without
     * `/Length`) + the already-encoded data.
     *
     * @return array{string, string}
     */
    public static function stream(string $entries, string $data): array
    {
        return ['<< ' . ($entries === '' ? '' : $entries . ' ') . '/Length ' . strlen($data) . ' >>', $data];
    }

    public static function ref(int $number): string
    {
        return $number . ' 0 R';
    }

    /**
     * The whole file. `$headerVersion` is e.g. `1.3`; `$id` is the 32-hex
     * document ID written twice (original = current) into the trailer.
     */
    public function toPdf(string $headerVersion, int $root, int $info, string $id): string
    {
        // Header + a binary comment line so transfer tools treat the file as binary.
        $chunks = ["%PDF-" . $headerVersion . "\n%\xE2\xE3\xCF\xD3\n"];
        $position = strlen($chunks[0]);
        $offsets = [];

        foreach ($this->objects as $number => $object) {
            if ($object === null) {
                throw new \LogicException(sprintf('PDF object %d was reserved but never written.', $number));
            }

            $offsets[$number] = $position;

            if (is_array($object)) {
                $parts = [$number . " 0 obj\n" . $object[0] . "\nstream\n", $object[1], "\nendstream\nendobj\n"];
            } else {
                $parts = [$number . " 0 obj\n" . $object . "\nendobj\n"];
            }

            foreach ($parts as $part) {
                $chunks[] = $part;
                $position += strlen($part);
            }
        }

        $size = count($this->objects) + 1;
        $xref = "xref\n0 " . $size . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $xref .= sprintf("%010d 00000 n \n", $offset);
        }

        $chunks[] = $xref;
        $chunks[] = "trailer\n<< /Size " . $size . ' /Root ' . self::ref($root) . ' /Info ' . self::ref($info)
            . ' /ID [ <' . $id . '> <' . $id . "> ] >>\n"
            . "startxref\n" . $position . "\n%%EOF\n";

        return implode('', $chunks);
    }

    public static function deflate(string $bytes): string
    {
        $compressed = gzcompress($bytes, 9);

        if ($compressed === false) {
            throw new \RuntimeException('Could not deflate a PDF stream.');
        }

        return $compressed;
    }

    /**
     * @param array{float, float, float, float} $box
     */
    public static function box(array $box): string
    {
        return '[ ' . implode(' ', array_map(self::number(...), $box)) . ' ]';
    }

    public static function number(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');

        return $formatted === '-0' || $formatted === '' ? '0' : $formatted;
    }

    /**
     * A PDF text string: plain literal for ASCII, UTF-16BE hex otherwise.
     */
    public static function text(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return '(' . strtr($value, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ')';
        }

        $utf16 = mb_convert_encoding($value, 'UTF-16BE', 'UTF-8');

        return '<FEFF' . strtoupper(bin2hex($utf16)) . '>';
    }

    /**
     * A PDF date string (`D:YYYYMMDDHHmmSSOHH'mm'`) — without the parentheses.
     */
    public static function date(\DateTimeImmutable $date): string
    {
        $offset = $date->format('P');

        return 'D:' . $date->format('YmdHis') . ($offset === '+00:00' ? 'Z' : str_replace(':', "'", $offset) . "'");
    }

    /**
     * The trailer `/ID`: a digest of the title, the date and EVERY page
     * image, so two exports differ exactly when their content does.
     *
     * @param list<string> $images
     */
    public static function documentId(string $title, string $date, array $images): string
    {
        $context = hash_init('md5');
        hash_update($context, $title . $date);

        foreach ($images as $image) {
            hash_update($context, strlen($image) . md5($image));
        }

        return hash_final($context);
    }
}
