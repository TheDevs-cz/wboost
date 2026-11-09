<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Image;

use AsyncAws\Core\Exception\Exception as AsyncAwsException;
use League\Flysystem\FilesystemReader;
use League\Flysystem\UnableToReadFile;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use WBoost\Web\Services\Image\ReadStoredImagePixelSize;

/**
 * Sentry WEB-4W: an S3 HTTP 429 escaped the header read and crashed
 * `app:manual:import-images-to-gallery`. AsyncAws responses are lazy, so the
 * HTTP error can surface from `readStream()` or from reading the stream —
 * both must answer null ("size unknown") with a warning, never throw.
 *
 * @covers \WBoost\Web\Services\Image\ReadStoredImagePixelSize
 */
final class ReadStoredImagePixelSizeTest extends TestCase
{
    /** 1×1 transparent PNG. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private null|RecordingLogger $logger = null;

    public function testAnHttpErrorOpeningTheStreamIsUnknownSize(): void
    {
        $reader = $this->createStub(FilesystemReader::class);
        $reader->method('readStream')->willThrowException(self::throttled());

        self::assertNull($this->service($reader)->read('manuals/x/intro.png'));
        self::assertSame([['level' => 'warning', 'message' => 'Could not read the stored image header of {path}: {message}']], $this->logger?->records);
    }

    public function testAnHttpErrorWhileReadingTheStreamIsUnknownSize(): void
    {
        if (!in_array('wboost-throwing', stream_get_wrappers(), true)) {
            stream_wrapper_register('wboost-throwing', ThrowingStreamWrapper::class);
        }

        $stream = fopen('wboost-throwing://object', 'r');
        self::assertIsResource($stream);

        $reader = $this->createStub(FilesystemReader::class);
        $reader->method('readStream')->willReturn($stream);

        self::assertNull($this->service($reader)->read('manuals/x/intro.png'));
        self::assertFalse(is_resource($stream), 'The stream is closed even when the read fails.');
        self::assertSame('warning', $this->logger?->records[0]['level'] ?? null);
    }

    public function testAMissingObjectStaysSilentlyUnknown(): void
    {
        $reader = $this->createStub(FilesystemReader::class);
        $reader->method('readStream')->willThrowException(UnableToReadFile::fromLocation('manuals/x/intro.png'));

        self::assertNull($this->service($reader)->read('manuals/x/intro.png'));
        self::assertSame([], $this->logger?->records);
    }

    public function testAReadablePictureReportsItsSize(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, (string) base64_decode(self::PNG));
        rewind($stream);

        $reader = $this->createStub(FilesystemReader::class);
        $reader->method('readStream')->willReturn($stream);

        self::assertSame(['width' => 1, 'height' => 1], $this->service($reader)->read('manuals/x/intro.png'));
    }

    public static function throttled(): AsyncAwsException
    {
        return new class ('HTTP 429 returned for "http://minio/wboost/manuals/x/intro.png".') extends \RuntimeException implements AsyncAwsException {
        };
    }

    private function service(FilesystemReader $reader): ReadStoredImagePixelSize
    {
        $this->logger = new RecordingLogger();

        return new ReadStoredImagePixelSize($reader, $this->logger);
    }
}

/**
 * @internal
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => is_string($level) ? $level : 'unknown', 'message' => (string) $message];
    }
}

/**
 * Stands in for AsyncAws' lazy result stream: the HTTP error is thrown from
 * the first read, not from opening the stream.
 *
 * @internal
 */
final class ThrowingStreamWrapper
{
    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, null|string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        throw ReadStoredImagePixelSizeTest::throttled();
    }

    /**
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_close(): void
    {
    }
}
