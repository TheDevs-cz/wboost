<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Instagram;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\Instagram\InstagramImageFit;
use WBoost\Web\Value\InstagramPostKind;

/**
 * @covers \WBoost\Web\Services\Instagram\InstagramImageFit
 */
final class InstagramImageFitTest extends TestCase
{
    public function testFeedPictureInsideTheRatioIsOnlyConverted(): void
    {
        self::assertSame([1080, 1350], $this->sizeOf((new InstagramImageFit())->toJpeg($this->png(1080, 1350), InstagramPostKind::Feed)));
    }

    public function testTooTallFeedPictureIsPaddedTo4By5(): void
    {
        self::assertSame([800, 1000], $this->sizeOf((new InstagramImageFit())->toJpeg($this->png(500, 1000), InstagramPostKind::Feed)));
    }

    public function testTooWideFeedPictureIsPaddedTo191By1(): void
    {
        self::assertSame([1000, 524], $this->sizeOf((new InstagramImageFit())->toJpeg($this->png(1000, 300), InstagramPostKind::Feed)));
    }

    public function testPrintSizeIsDownscaledTo1440Wide(): void
    {
        [$width, $height] = $this->sizeOf((new InstagramImageFit())->toJpeg($this->png(2480, 3508), InstagramPostKind::Feed));

        self::assertSame(1440, $width);
        self::assertSame(1800, $height); // A4 padded to 4:5 first, then scaled down
    }

    public function testStoryIsNotPadded(): void
    {
        self::assertSame([500, 1000], $this->sizeOf((new InstagramImageFit())->toJpeg($this->png(500, 1000), InstagramPostKind::Story)));
    }

    private function png(int $width, int $height): string
    {
        $image = new \Imagick();
        $image->newImage($width, $height, new \ImagickPixel('red'));
        $image->setImageFormat('png');

        return $image->getImagesBlob();
    }

    /**
     * @return array{int, int}
     */
    private function sizeOf(string $jpeg): array
    {
        $image = new \Imagick();
        $image->readImageBlob($jpeg);
        self::assertSame('JPEG', $image->getImageFormat());

        return [$image->getImageWidth(), $image->getImageHeight()];
    }
}
