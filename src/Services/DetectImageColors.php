<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

readonly final class DetectImageColors
{
    /**
     * Extracts unique colors from SVG content using regex patterns for hex and rgb/rgba formats.
     *
     * @return array<string>
     */
    public function fromSvg(string $fileContent): array
    {
        // Extract hex colors (e.g., #abc or #aabbcc) with case-insensitive matching
        preg_match_all('/#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?\b/', $fileContent, $hexMatches);

        // Extract rgb or rgba colors (e.g., rgb(255, 255, 255) or rgba(255, 255, 255, 0.5))
        preg_match_all('/rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}(?:\s*,\s*(?:0(?:\.\d+)?|1(?:\.0+)?))?\s*\)/', $fileContent, $rgbMatches);

        $colors = array_merge($hexMatches[0], $rgbMatches[0]);
        $colors = array_values(array_unique($colors));

        sort($colors);

        return $colors;
    }
}
