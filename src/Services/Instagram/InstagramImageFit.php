<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Instagram;

use WBoost\Web\Value\InstagramPostKind;

/**
 * Makes ANY rendered picture acceptable to Instagram: PNG → JPEG (alpha
 * flattened onto white), for a feed post outside 4:5 – 1.91:1 centred on a
 * white canvas of the nearest accepted ratio, then downscaled to at most
 * 1440 px wide (Instagram's own maximum — a 300 DPI print size would
 * otherwise be a huge upload). Stories are taken as they are.
 */
final class InstagramImageFit
{
    public const int MAX_WIDTH = 1440;

    public function toJpeg(string $pngBytes, InstagramPostKind $kind, int $quality = 90): string
    {
        $image = new \Imagick();
        $image->readImageBlob($pngBytes);
        $image->setImageBackgroundColor(new \ImagickPixel('#ffffff'));
        $image = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);

        if ($kind === InstagramPostKind::Feed) {
            [$width, $height] = self::paddedSize($image->getImageWidth(), $image->getImageHeight());

            if ($width !== $image->getImageWidth() || $height !== $image->getImageHeight()) {
                $image->setImageBackgroundColor(new \ImagickPixel('#ffffff'));
                $image->extentImage(
                    $width,
                    $height,
                    -intdiv($width - $image->getImageWidth(), 2),
                    -intdiv($height - $image->getImageHeight(), 2),
                );
            }
        }

        if ($image->getImageWidth() > self::MAX_WIDTH) {
            $image->resizeImage(self::MAX_WIDTH, 0, \Imagick::FILTER_LANCZOS, 1);
        }

        $image->setImageFormat('jpeg');
        $image->setImageCompressionQuality($quality);

        return $image->getImagesBlob();
    }

    /**
     * The canvas a feed picture of this size needs (unchanged when it fits).
     *
     * @return array{int, int}
     */
    public static function paddedSize(int $width, int $height): array
    {
        $ratio = $width / max(1, $height);

        if ($ratio < InstagramPostKind::MIN_FEED_RATIO) {
            return [(int) ceil($height * InstagramPostKind::MIN_FEED_RATIO), $height];
        }

        if ($ratio > InstagramPostKind::MAX_FEED_RATIO) {
            return [$width, (int) ceil($width / InstagramPostKind::MAX_FEED_RATIO)];
        }

        return [$width, $height];
    }
}
