<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Image;

use AsyncAws\Core\Exception\Exception as AsyncAwsException;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemReader;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;

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
 * the bucket, a header PHP cannot decode, or a storage HTTP failure (S3 429 /
 * 5xx / network — AsyncAws responses are lazy, so those surface from
 * `readStream()` OR from reading the stream; Sentry WEB-4W crashed the manual
 * gallery import that way). Callers never get a guess — a wrong aspect ratio
 * mis-crops every placement made from it.
 */
readonly final class ReadStoredImagePixelSize
{
    private const int HEADER_BYTES = 262144;

    public function __construct(
        #[Autowire(service: 'oneup_flysystem.minio_filesystem')]
        private FilesystemReader $filesystem,
        private LoggerInterface $logger,
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
        $stream = null;

        try {
            $stream = $this->filesystem->readStream($path);
            $header = stream_get_contents($stream, self::HEADER_BYTES);
        } catch (FilesystemException) {
            return null;
        } catch (AsyncAwsException | HttpClientException $exception) {
            // A transient storage failure is "size unknown", not a crash —
            // the caller (a sweep over hundreds of pictures) carries on.
            $this->logger->warning('Could not read the stored image header of {path}: {message}', [
                'path' => $path,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return null;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $header === false || $header === '' ? null : $header;
    }
}
