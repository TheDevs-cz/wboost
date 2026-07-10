<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

/**
 * PNG → JPEG conversion for Instagram publishing (its API accepts JPEG only).
 * Transparency is flattened onto white — JPEG has no alpha channel.
 */
readonly final class PngToJpeg
{
    public function convert(string $pngBytes, int $quality = 90): string
    {
        $image = new \Imagick();
        $image->readImageBlob($pngBytes);
        $image->setImageBackgroundColor(new \ImagickPixel('#ffffff'));

        $flattened = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
        $flattened->setImageFormat('jpeg');
        $flattened->setImageCompressionQuality($quality);

        return $flattened->getImagesBlob();
    }
}
