<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Print\Cmyk;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

use WBoost\Web\Exceptions\PdfStructureNotSupported;
use WBoost\Web\Services\Print\PdfPageBoxes;

/**
 * Random access to the objects of a PDF file as {@see PdfSyntax} values.
 * Reads what {@see PdfPageBoxes::structure()} reads — a classic
 * cross-reference table, generation 0, no updates, no encryption: what
 * Chromium prints and what {@see PdfWriter} writes. A stream is never loaded
 * unless asked for; {@see copyStream()} moves it in pieces.
 */
#[Exclude]
final class PdfReader
{
    private const int HEAD_BYTES = 262_144;

    private const int MAX_VALUE_BYTES = 16_000_000;

    private const int COPY_BYTES = 1_048_576;

    /** @var resource */
    private $file;

    /** @var array<int, int> object number → offset */
    private array $offsets;

    /** @var array<int, int> object number → bytes up to the next object */
    private array $extents = [];

    /** @var array<int, array{value: mixed, stream: null|array{int, int}}> */
    private array $cache = [];

    public readonly int $root;

    public readonly int $size;

    /**
     * @throws PdfStructureNotSupported
     */
    public function __construct(string $path)
    {
        $file = @fopen($path, 'rb');

        if ($file === false) {
            throw new PdfStructureNotSupported('The PDF file cannot be read.');
        }

        $this->file = $file;
        $structure = PdfPageBoxes::structure($file);
        $this->offsets = $structure['offsets'];
        $this->root = $structure['root'];
        $this->size = $structure['objectCount'];

        $sorted = $this->offsets;
        asort($sorted);
        $numbers = array_keys($sorted);
        $starts = array_values($sorted);

        foreach ($numbers as $index => $number) {
            $this->extents[$number] = ($starts[$index + 1] ?? $structure['xrefOffset']) - $starts[$index];

            if ($this->extents[$number] <= 0) {
                throw new PdfStructureNotSupported('The PDF objects are not laid out one after another.');
            }
        }
    }

    public function __destruct()
    {
        if (is_resource($this->file)) {
            fclose($this->file);
        }
    }

    /** @return list<int> */
    public function numbers(): array
    {
        $numbers = array_keys($this->offsets);
        sort($numbers);

        return $numbers;
    }

    public function has(int $number): bool
    {
        return isset($this->offsets[$number]);
    }

    /** The value of an object — for a stream object its dictionary. */
    public function value(int $number): mixed
    {
        return $this->object($number)['value'];
    }

    public function isStream(int $number): bool
    {
        return $this->object($number)['stream'] !== null;
    }

    /** Bytes of the (still encoded) stream. */
    public function streamLength(int $number): int
    {
        return $this->streamRange($number)[1];
    }

    /** The stream as it lies in the file (still encoded). */
    public function streamBytes(int $number, int $limit = 64_000_000): string
    {
        [$offset, $length] = $this->streamRange($number);

        if ($length > $limit) {
            throw new PdfStructureNotSupported(sprintf('The stream of PDF object %d is too large to hold in memory.', $number));
        }

        if ($length === 0) {
            return '';
        }

        fseek($this->file, $offset);
        $bytes = '';

        while (strlen($bytes) < $length) {
            $chunk = fread($this->file, max(1, $length - strlen($bytes)));

            if ($chunk === false || $chunk === '') {
                throw new PdfStructureNotSupported(sprintf('The stream of PDF object %d ends before its length.', $number));
            }

            $bytes .= $chunk;
        }

        return $bytes;
    }

    /**
     * The (still encoded) stream, piece by piece.
     *
     * @param callable(string): void $sink
     */
    public function copyStream(int $number, callable $sink): void
    {
        [$offset, $length] = $this->streamRange($number);
        $position = $offset;
        $end = $offset + $length;

        while ($position < $end) {
            // the sink may read other objects in between: seek every time
            fseek($this->file, $position);
            $chunk = fread($this->file, max(1, min(self::COPY_BYTES, $end - $position)));

            if ($chunk === false || $chunk === '') {
                throw new PdfStructureNotSupported(sprintf('The stream of PDF object %d ends before its length.', $number));
            }

            $position += strlen($chunk);
            $sink($chunk);
        }
    }

    /**
     * A value with one level of indirection removed: a reference to a
     * non-stream object answers that object's value.
     */
    public function resolve(mixed $value): mixed
    {
        for ($hops = 0; $value instanceof PdfRef && $hops < 8; ++$hops) {
            if (!$this->has($value->number)) {
                // a reference to nothing is the null object
                return 'null';
            }

            if ($this->isStream($value->number)) {
                return $value;
            }

            $value = $this->value($value->number);
        }

        return $value;
    }

    /**
     * @return array{int, int} offset and length of the stream data
     */
    private function streamRange(int $number): array
    {
        $stream = $this->object($number)['stream'];

        if ($stream === null) {
            throw new PdfStructureNotSupported(sprintf('The PDF object %d is not a stream.', $number));
        }

        return $stream;
    }

    /**
     * @return array{value: mixed, stream: null|array{int, int}}
     */
    private function object(int $number): array
    {
        if (isset($this->cache[$number])) {
            return $this->cache[$number];
        }

        if (!isset($this->offsets[$number])) {
            throw new PdfStructureNotSupported(sprintf('The PDF object %d is not in the cross-reference table.', $number));
        }

        $offset = $this->offsets[$number];
        $extent = $this->extents[$number];
        $want = min($extent, self::HEAD_BYTES);

        while (true) {
            fseek($this->file, $offset);
            $head = $want > 0 ? (string) fread($this->file, $want) : '';

            // fread may answer less than asked for on some stream wrappers
            while (strlen($head) < $want) {
                $more = fread($this->file, max(1, $want - strlen($head)));

                if ($more === false || $more === '') {
                    break;
                }

                $head .= $more;
            }

            try {
                $parsed = $this->parseObject($head, $number, $offset, $extent, strlen($head) >= $extent);

                break;
            } catch (PdfStructureNotSupported $incomplete) {
                if (strlen($head) >= $extent || $want >= self::MAX_VALUE_BYTES) {
                    throw $incomplete;
                }

                $want = min($extent, $want * 8, self::MAX_VALUE_BYTES);
            }
        }

        // big dictionaries are rare and streams are never held: the cache stays small
        return $this->cache[$number] = $parsed;
    }

    /**
     * @return array{value: mixed, stream: null|array{int, int}}
     */
    private function parseObject(string $head, int $number, int $offset, int $extent, bool $whole): array
    {
        if (preg_match('/^\s*(\d+)\s+0\s+obj/', $head, $header) !== 1 || (int) $header[1] !== $number) {
            throw new PdfStructureNotSupported(sprintf('The PDF object %d is not where the cross-reference table says.', $number));
        }

        $at = strlen($header[0]);
        $value = PdfSyntax::parse($head, $at);

        if (preg_match('/\G\s*stream(\r\n|\n|\r)/', $head, $keyword, 0, $at) !== 1) {
            if (preg_match('/\G\s*endobj/', $head, $end, 0, $at) !== 1) {
                if (!$whole && strlen($head) - $at < 16) {
                    throw new PdfStructureNotSupported(sprintf('The PDF object %d is cut off.', $number));
                }

                throw new PdfStructureNotSupported(sprintf('The PDF object %d does not end with endobj.', $number));
            }

            return ['value' => $value, 'stream' => null];
        }

        if (!$value instanceof PdfDict) {
            throw new PdfStructureNotSupported(sprintf('The PDF stream object %d has no dictionary.', $number));
        }

        $length = $value->get('Length');

        if ($length instanceof PdfRef) {
            $length = $this->value($length->number);
        }

        if (!is_string($length) || !ctype_digit($length)) {
            throw new PdfStructureNotSupported(sprintf('The PDF stream object %d has no usable /Length.', $number));
        }

        $start = $at + strlen($keyword[0]);
        $length = (int) $length;

        if ($start + $length > $extent) {
            throw new PdfStructureNotSupported(sprintf('The stream of PDF object %d is longer than the object.', $number));
        }

        fseek($this->file, $offset + $start + $length);
        $tail = (string) fread($this->file, max(1, min(32, $extent - $start - $length)));

        if (preg_match('/^\s*endstream/', $tail) !== 1) {
            throw new PdfStructureNotSupported(sprintf('The stream of PDF object %d does not end where its /Length says.', $number));
        }

        return ['value' => $value, 'stream' => [$offset + $start, $length]];
    }
}
