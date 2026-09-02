<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Image;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemReader;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads the pixel size of a picture already sitting in the object store, from
 * a bounded PREFIX of it.
 *
 * Every raster format wboost stores (PNG, JPEG, GIF, WebP — the upload
 * normaliser guarantees it) declares its size in a header near the start of
 * the file. A JPEG with a large embedded colour profile or preview thumbnail
 * is the worst case; {@see HEADER_BYTES} covers every real file while keeping
 * a sweep over hundreds of pictures from transferring their full weight.
 *
 * Null is the honest answer whenever there is no size to report: an SVG (a
 * vector scales to whatever box it is placed in), an object that vanished from
 * the bucket, a header PHP cannot decode. Callers never get a guess — a wrong
 * aspect ratio mis-crops every placement made from it.
 */
readonly final class ReadStoredImagePixelSize
{
    private const int HEADER_BYTES = 262144;

    public function __construct(
        #[Autowire(service: 'oneup_flysystem.minio_filesystem')]
        private FilesystemReader $filesystem,
    ) {
    }

    /**
     * @return null|array{width: int, height: int}
     */
    public function read(string $path): null|array
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'svg') {
            return null;
        }

        $header = $this->readHeader($path);

        if ($header === null) {
            return null;
        }

        // Suppressed: a header too short to decode (a truncated prefix, a
        // format PHP cannot read at all) is an expected outcome here, answered
        // with null rather than a warning in the log.
        $size = @getimagesizefromstring($header);

        if ($size === false || $size[0] <= 0 || $size[1] <= 0) {
            return null;
        }

        return ['width' => $size[0], 'height' => $size[1]];
    }

    /**
     * A bounded prefix of a stored file, or null when it cannot be read. The
     * stream is closed immediately, so the rest of a large object is never
     * transferred.
     */
    private function readHeader(string $path): null|string
    {
        try {
            $stream = $this->filesystem->readStream($path);
        } catch (FilesystemException) {
            return null;
        }

        $header = stream_get_contents($stream, self::HEADER_BYTES);
        fclose($stream);

        return $header === false || $header === '' ? null : $header;
    }
}
