<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A file the admin attached to a mockup page so manual readers can download it
 * — the print-ready PDF behind a mockup, the source archive, a cutting plan.
 *
 * The stored `path` is a timestamped key (like every other upload here), so
 * the name the file was uploaded under is kept separately: it is what the
 * download is served as. `mimeType` is recorded from the uploaded BYTES at
 * upload time, so serving never has to re-read the object to label it.
 */
readonly final class MockupPageDownload
{
    public function __construct(
        public string $path,
        public string $fileName,
        public int $size,
        public string $mimeType,
    ) {
    }

    public static function fromUpload(UploadedFile $file, string $path): self
    {
        return new self(
            $path,
            self::sanitizeName($file->getClientOriginalName()) ?? basename($path),
            $file->getSize() === false ? 0 : $file->getSize(),
            $file->getMimeType() ?? 'application/octet-stream',
        );
    }

    /**
     * Defensive on purpose — a hand-edited row must never fatal a manual render.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): null|self
    {
        $path = $data['path'] ?? null;

        if (!is_string($path) || $path === '') {
            return null;
        }

        $fileName = $data['fileName'] ?? null;
        $size = $data['size'] ?? null;
        $mimeType = $data['mimeType'] ?? null;

        return new self(
            $path,
            is_string($fileName) && $fileName !== '' ? $fileName : basename($path),
            is_int($size) && $size >= 0 ? $size : 0,
            is_string($mimeType) && $mimeType !== '' ? $mimeType : 'application/octet-stream',
        );
    }

    /**
     * @return array{path: string, fileName: string, size: int, mimeType: string}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'fileName' => $this->fileName,
            'size' => $this->size,
            'mimeType' => $this->mimeType,
        ];
    }

    /**
     * Uppercase extension of the uploaded name ("PDF", "ZIP"), for the badge
     * next to a download button. Empty when the name carries no extension.
     * The byte count is rendered by the shared `file_size` Twig filter.
     */
    public function format(): string
    {
        $extension = pathinfo($this->fileName, PATHINFO_EXTENSION);

        return strtoupper(mb_substr($extension, 0, 8));
    }

    /**
     * Mirrors UploadFileHandler::originalName — the value is client-controlled,
     * so path separators and control characters are dropped and it is cut to
     * the width the JSON document is expected to carry.
     */
    private static function sanitizeName(string $name): null|string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        return mb_substr($name, 0, 255);
    }
}
