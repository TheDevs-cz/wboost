<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

use League\Flysystem\Filesystem;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Exceptions\InvalidColorMapping;

final class SvgColorsMapper
{
    /**
     * @var array<string, string>
     */
    private array $images = [];

    public function __construct(
        readonly private Filesystem $filesystem,
    ) {
    }

    /**
     * @param array<int, int|string> $replacementMap
     */
    public function map(string $image, Manual $manual, array $replacementMap): string
    {
        $svgContent = $this->getSvgContent($image);

        if ($replacementMap !== []) {
            $mapFrom = [];
            $mapTo = [];

            foreach ($manual->colorsMapping as $mapping) {
                $sourcePrimaryColorNumber = $replacementMap[$mapping->targetPrimaryColorNumber] ?? null;

                if ($sourcePrimaryColorNumber === null) {
                    continue;
                }

                if (is_int($sourcePrimaryColorNumber)) {
                    $colorToReplaceWith = $manual->getPrimaryColor($sourcePrimaryColorNumber)?->hex;
                } else {
                    $colorToReplaceWith = trim($sourcePrimaryColorNumber, '#');
                }

                $mapFrom[] = $mapping->colorHex;
                $mapTo[] = $colorToReplaceWith;
            }

            $svgContent = str_replace($mapFrom, $mapTo, $svgContent);
        }

        return $svgContent;
    }

    /**
     * @param array<int, int|string> $replacementMap
     */
    public function mapToDataUri(string $image, Manual $manual, array $replacementMap): string
    {
        $svgContent = $this->map($image, $manual, $replacementMap);

        return 'data:image/svg+xml;base64,' . base64_encode($svgContent);
    }

    private function getSvgContent(string $filePath): string
    {
        if (!isset($this->images[$filePath])) {
            $svgContent = $this->filesystem->read($filePath);

            $this->images[$filePath] = $svgContent;
        }

        return $this->images[$filePath];
    }
}
