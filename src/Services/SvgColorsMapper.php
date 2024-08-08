<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

use WBoost\Web\Entity\Manual;
use WBoost\Web\Exceptions\InvalidColorMapping;

final class SvgColorsMapper
{
    /**
     * @var array<string, string>
     */
    private array $images = [];

    public function __construct(
        readonly private UploaderHelper $uploaderHelper,
    ) {
    }

    /**
     * @param array<string, string> $replacementMap
     * @throws InvalidColorMapping
     */
    public function map(string $image, Manual $manual, array $replacementMap): string
    {
        $svgContent = $this->getSvgContent($image);

        if ($replacementMap !== []) {
            $mapFrom = [];
            $mapTo = [];

            foreach ($manual->colorMapping as $mapping) {
                if (!isset($replacementMap[$mapping['target']])) {
                    continue;
                }

                if (str_starts_with($replacementMap[$mapping['target']], '#')) {
                    $mapToColor = $replacementMap[$mapping['target']];
                } else {
                    $mapToColor = $this->matchColor($manual, $replacementMap[$mapping['target']]);
                }

                $mapFrom[] = $mapping['source'];
                $mapTo[] = $mapToColor;
            }

            $svgContent = str_replace($mapFrom, $mapTo, $svgContent);
        }

        return 'data:image/svg+xml;base64,' . base64_encode($svgContent);
    }

    /**
     * @throws InvalidColorMapping
     */
    private function matchColor(Manual $manual, string $colorName): null|string
    {
        return match (strtoupper($colorName)) {
            'C1' => $manual->color1,
            'C2' => $manual->color2,
            'C3' => $manual->color3,
            'C4' => $manual->color4,
            default => throw new InvalidColorMapping(),
        };
    }

    private function getSvgContent(string $filePath): string
    {
        if (!isset($this->images[$filePath])) {
            $svgContent = file_get_contents($this->uploaderHelper->getInternalPath($filePath));
            assert($svgContent !== false);

            $this->images[$filePath] = $svgContent;
        }

        return $this->images[$filePath];
    }
}
