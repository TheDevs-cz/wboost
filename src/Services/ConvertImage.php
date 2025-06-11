<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

readonly final class ConvertImage
{
    /**
     * @throws ProcessFailedException
     */
    public function svgToPng(string $svgContent, int $density = 300): string
    {
        $outPath = tempnam(sys_get_temp_dir(), 'png_') . '.png';
        $inPath = tempnam(sys_get_temp_dir(), 'svg_') . '.svg';
        file_put_contents($inPath, $svgContent);

        $process = new Process([
            'inkscape',
            '--export-type=png',
            '--export-filename=' . $outPath,
            '--export-dpi', (string)$density,
            $inPath,
        ]);
        $process->mustRun();

        $pngData = file_get_contents($outPath);

        @unlink($inPath);
        @unlink($outPath);

        assert(is_string($pngData));

        return $pngData;
    }

    public function svgToJpg(
        string $svgContent,
        int    $quality       = 100,
        string $backgroundHex = '#ffffff',
        int    $density       = 300
    ): string {
        // 1) Get a PNG raster from the SVG
        $pngData = $this->svgToPng($svgContent, $density);

        // 2) Create a GD image from that PNG data
        $srcImg = @imagecreatefromstring($pngData);
        if ($srcImg === false) {
            throw new \RuntimeException('Failed to create image from PNG data');
        }

        $width  = imagesx($srcImg);
        $height = imagesy($srcImg);

        // 3) Prepare a truecolor canvas for the JPEG
        $dstImg = imagecreatetruecolor($width, $height);

        // 4) Parse and allocate the background color
        if (!preg_match('/^#?([A-Fa-f0-9]{6})$/', $backgroundHex, $m)) {
            $backgroundHex = 'ffffff';
        } else {
            $backgroundHex = $m[1];
        }

        /** @var int<0, 255> $r */
        $r = hexdec(substr($backgroundHex, 0, 2));

        /** @var int<0, 255> $g */
        $g = hexdec(substr($backgroundHex, 2, 2));

        /** @var int<0, 255> $b */
        $b = hexdec(substr($backgroundHex, 4, 2));

        /** @var int<0, max> $bgColor */
        $bgColor = imagecolorallocate($dstImg, $r, $g, $b);

        imagefilledrectangle($dstImg, 0, 0, $width, $height, $bgColor);

        // 5) Composite the PNG over the background
        imagecopy($dstImg, $srcImg, 0, 0, 0, 0, $width, $height);

        // 6) Output the JPEG to memory
        ob_start();
        imagejpeg($dstImg, null, $quality);
        $jpegData = ob_get_clean();

        // 7) Clean up
        imagedestroy($srcImg);
        imagedestroy($dstImg);

        assert(is_string($jpegData));

        return $jpegData;
    }
}
