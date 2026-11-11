<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Print\Cmyk;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

use WBoost\Web\Exceptions\PdfStructureNotSupported;

/**
 * Writes a PDF file object by object, in any order of numbers, and closes it
 * with a classic cross-reference table — the structure {@see PdfReader} and
 * {@see \WBoost\Web\Services\Print\PdfChunkMerger} read. Numbers that were
 * never written are free entries.
 */
#[Exclude]
final class PdfWriter
{
    private const int COPY_BYTES = 1_048_576;

    /** @var resource */
    private $file;

    private int $position = 0;

    /** @var array<int, int> */
    private array $offsets = [];

    public function __construct(string $path, string $version = '1.6')
    {
        $file = @fopen($path, 'wb');

        if ($file === false) {
            throw new PdfStructureNotSupported('The PDF file cannot be written.');
        }

        $this->file = $file;
        $this->put(sprintf("%%PDF-%s\n%%\xE2\xE3\xCF\xD3\n", $version));
    }

    public function isWritten(int $number): bool
    {
        return isset($this->offsets[$number]);
    }

    public function object(int $number, mixed $value): void
    {
        $this->begin($number);
        $this->put(sprintf("%d 0 obj\n%s\nendobj\n", $number, PdfSyntax::serialize($value)));
    }

    /** A stream object; `/Length` is set here. */
    public function stream(int $number, PdfDict $dictionary, string $data): void
    {
        $this->begin($number);
        $dictionary->set('Length', (string) strlen($data));
        $this->put(sprintf("%d 0 obj\n%s\nstream\n", $number, PdfSyntax::serialize($dictionary)));
        $this->put($data);
        $this->put("\nendstream\nendobj\n");
    }

    /** A stream object whose data is the content of a file. */
    public function streamFromFile(int $number, PdfDict $dictionary, string $path): void
    {
        $in = @fopen($path, 'rb');
        $size = @filesize($path);

        if ($in === false || $size === false) {
            throw new PdfStructureNotSupported('A converted stream cannot be read back.');
        }

        try {
            $this->begin($number);
            $dictionary->set('Length', (string) $size);
            $this->put(sprintf("%d 0 obj\n%s\nstream\n", $number, PdfSyntax::serialize($dictionary)));

            while (!feof($in)) {
                $chunk = fread($in, self::COPY_BYTES);

                if ($chunk === false) {
                    throw new PdfStructureNotSupported('A converted stream cannot be read back.');
                }

                $this->put($chunk);
            }

            $this->put("\nendstream\nendobj\n");
        } finally {
            fclose($in);
        }
    }

    /** A stream object whose data is copied from another file as it is. */
    public function streamFromReader(int $number, PdfDict $dictionary, PdfReader $reader, int $source): void
    {
        $this->begin($number);
        $dictionary->set('Length', (string) $reader->streamLength($source));
        $this->put(sprintf("%d 0 obj\n%s\nstream\n", $number, PdfSyntax::serialize($dictionary)));
        $reader->copyStream($source, $this->put(...));
        $this->put("\nendstream\nendobj\n");
    }

    /**
     * The cross-reference table and the trailer; the file is closed.
     *
     * @param int $size one more than the highest object number
     */
    public function finish(int $root, int $size): void
    {
        $xrefAt = $this->position;
        $xref = sprintf("xref\n0 %d\n0000000000 65535 f \n", $size);

        for ($number = 1; $number < $size; ++$number) {
            $xref .= isset($this->offsets[$number])
                ? sprintf("%010d 00000 n \n", $this->offsets[$number])
                : "0000000000 65535 f \n";
        }

        $this->put($xref . sprintf("trailer\n<< /Size %d /Root %d 0 R >>\nstartxref\n%d\n%%%%EOF\n", $size, $root, $xrefAt));
        fclose($this->file);
    }

    public function abort(): void
    {
        if (is_resource($this->file)) {
            fclose($this->file);
        }
    }

    private function begin(int $number): void
    {
        if (isset($this->offsets[$number])) {
            throw new \LogicException(sprintf('PDF object %d is written twice.', $number));
        }

        $this->offsets[$number] = $this->position;
    }

    private function put(string $bytes): void
    {
        $length = strlen($bytes);

        if ($length > 0 && fwrite($this->file, $bytes) !== $length) {
            throw new PdfStructureNotSupported('The PDF file could not be written (disk full?).');
        }

        $this->position += $length;
    }
}
