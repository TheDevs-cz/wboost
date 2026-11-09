<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Manual;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Services\Manual\MockupPageSlotImages;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * Content pages P4: a mockup slot upload goes through NormalizeImageFormat
 * like every gallery upload — a format the stack cannot read (HEIC off an
 * iPhone, TIFF, …) is stored as JPEG/PNG; web-safe formats pass untouched.
 *
 * @covers \WBoost\Web\Services\Manual\MockupPageSlotImages
 */
final class MockupPageSlotImagesTest extends KernelTestCase
{
    /** @var list<string> */
    private array $written = [];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            try {
                $this->filesystem()->delete($path);
            } catch (\Throwable) {
            }
        }

        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        $this->written = [];
        $this->tempFiles = [];

        parent::tearDown();
    }

    public function testAnUnreadableFormatIsStoredAsReadableJpeg(): void
    {
        $path = $this->resolve($this->image('tiff', 60, 30), 'scan.tiff');

        self::assertStringEndsWith('.jpg', $path);
        $size = @getimagesizefromstring($this->filesystem()->read($path));
        self::assertNotFalse($size);
        self::assertSame(IMAGETYPE_JPEG, $size[2]);
        self::assertSame([60, 30], [$size[0], $size[1]]);

        $row = self::getContainer()->get(EntityManagerInterface::class)->getRepository(FileUpload::class)->findOneBy(['path' => $path]);
        self::assertInstanceOf(FileUpload::class, $row);
        self::assertSame([60, 30], [$row->width, $row->height]);
    }

    public function testAnIphoneHeicPhotoIsStoredAsJpeg(): void
    {
        if (!in_array('HEIC', \Imagick::queryFormats(), true)) {
            self::markTestSkipped('ImageMagick was built without HEIC support.');
        }

        $path = $this->resolve($this->image('heic', 40, 20), 'IMG_0001.HEIC');

        self::assertStringEndsWith('.jpg', $path);
        self::assertNotFalse(@getimagesizefromstring($this->filesystem()->read($path)));
    }

    public function testAPngIsStoredUntouched(): void
    {
        $png = $this->image('png', 12, 6);
        $path = $this->resolve($png, 'logo.png');

        self::assertStringEndsWith('.png', $path);
        self::assertSame($png, $this->filesystem()->read($path));
    }

    public function testAnSvgKeepsItsBytes(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>';
        $path = $this->resolve($svg, 'shape.svg');

        self::assertStringEndsWith('.svg', $path);
        self::assertSame($svg, $this->filesystem()->read($path));
    }

    private function resolve(string $contents, string $name): string
    {
        $manual = self::getContainer()->get(EntityManagerInterface::class)->find(Manual::class, Uuid::fromString(TestDataFixture::MANUAL_1_ID));
        self::assertInstanceOf(Manual::class, $manual);

        $tmp = (string) tempnam(sys_get_temp_dir(), 'slot');
        file_put_contents($tmp, $contents);
        $this->tempFiles[] = $tmp;

        $path = self::getContainer()->get(MockupPageSlotImages::class)->resolve(
            $manual,
            Uuid::uuid7(),
            'Vizitky',
            0,
            new UploadedFile($tmp, $name, null, null, true),
            null,
            1_700_000_000,
        );
        self::assertIsString($path);
        $this->written[] = $path;
        // The handler's bus transaction flushes in real use.
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $path;
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
