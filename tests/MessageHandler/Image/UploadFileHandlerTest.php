<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\Image;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Message\Image\UploadFile;
use WBoost\Web\MessageHandler\Image\UploadFileHandler;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\FileSource;

/**
 * The upload handler is the one chokepoint every gallery image passes through,
 * so it is where a picture is made readable by the rest of the stack.
 */
final class UploadFileHandlerTest extends KernelTestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    /**
     * A photo straight off an iPhone: neither getimagesizefromstring() (the
     * natural-size read behind every placeholder fill) nor Chromium can read
     * HEIC, so it used to upload fine and then fail the export with "could not
     * be read or is not a supported raster image".
     */
    public function testStoresAnIphoneHeicPhotoAsReadableJpeg(): void
    {
        if (!in_array('HEIC', \Imagick::queryFormats(), true)) {
            self::markTestSkipped('ImageMagick was built without HEIC support.');
        }

        $file = $this->upload($this->image('heic', 80, 40), 'IMG_4821.HEIC');

        self::assertStringEndsWith('.jpg', $file->path);

        $stored = $this->filesystem()->read($file->path);
        $size = @getimagesizefromstring($stored);

        self::assertNotFalse($size, 'The stored bytes must be readable by the export path.');
        self::assertSame(IMAGETYPE_JPEG, $size[2]);
        self::assertSame([80, 40], [$size[0], $size[1]]);

        // The label stays the name the user knows the photo by; the recorded
        // size is that of the STORED (transcoded) picture.
        self::assertSame('IMG_4821.HEIC', $file->originalName);
        self::assertSame([80, 40], [$file->width, $file->height]);
    }

    public function testStoresPngUntouched(): void
    {
        $png = $this->image('png', 24, 12);
        $file = $this->upload($png, 'logo.png');

        self::assertStringEndsWith('.png', $file->path);
        self::assertSame($png, $this->filesystem()->read($file->path), 'A web-safe format is never re-encoded.');
        self::assertSame('logo.png', $file->originalName);
        self::assertSame([24, 12], [$file->width, $file->height], 'Recorded at upload so the gallery never has to read the file for it.');
    }

    /**
     * The extension describes the BYTES, not the name the client sent — the
     * data URI's mime type is derived from it at render time.
     */
    public function testCorrectsAnExtensionThatContradictsTheBytes(): void
    {
        $file = $this->upload($this->image('png', 10, 10), 'screenshot.jpg');

        self::assertStringEndsWith('.png', $file->path);
    }

    /** SVG stays vector — it must never be rasterized on the way in. */
    public function testStoresSvgUntouched(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>';
        $file = $this->upload($svg, 'logo.svg');

        self::assertStringEndsWith('.svg', $file->path);
        self::assertSame($svg, $this->filesystem()->read($file->path));
        self::assertSame('logo.svg', $file->originalName);
        self::assertFalse($file->hasPixelSize(), 'A vector has no pixel size.');
    }

    /**
     * The original name is a client-controlled string kept as a LABEL: path
     * parts and control characters are dropped, and an empty name is null
     * rather than an empty caption.
     */
    public function testOriginalNameIsSanitised(): void
    {
        $file = $this->upload($this->image('png', 4, 4), "..\\evil\x00dir/pozadí modré.png");

        self::assertSame('pozadí modré.png', $file->originalName);
    }

    private function upload(string $contents, string $clientName): \WBoost\Web\Entity\FileUpload
    {
        $path = tempnam(sys_get_temp_dir(), 'upload-test-');
        self::assertNotFalse($path);
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        $fileId = Uuid::uuid4();

        self::getContainer()->get(UploadFileHandler::class)(new UploadFile(
            $fileId,
            Uuid::fromString(TestDataFixture::PROJECT_1_ID),
            FileSource::ProjectImage,
            new UploadedFile($path, $clientName, null, null, true),
        ));

        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return self::getContainer()->get(FileUploadRepository::class)->get($fileId);
    }

    private function image(string $format, int $width, int $height): string
    {
        $image = new \Imagick();
        $image->newImage($width, $height, new \ImagickPixel('#3366cc'));
        $image->setImageFormat($format);

        return $image->getImageBlob();
    }

    private function filesystem(): Filesystem
    {
        return self::getContainer()->get(Filesystem::class);
    }
}
