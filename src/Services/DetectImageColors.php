<?php

declare(strict_types=1);

namespace BrandManuals\Web\Services;

use BrandManuals\Web\Exceptions\UnsupportedImageFormat;
use SimpleXMLElement;

readonly final class DetectImageColors
{
    /**
     * @return array<string>
     *
     * @throws UnsupportedImageFormat
     */
    public function fromImagePath(string $imagePath): array
    {
        if (str_ends_with($imagePath, '.svg') === false) {
            throw new UnsupportedImageFormat();
        }

        $fileContent = file_get_contents($imagePath);
        assert(is_string($fileContent));

        $svg = new SimpleXMLElement($fileContent);

        return $this->extractColorsFromSvg($svg);
    }

    /**
     * @return string[]
     */
    public function extractColorsFromSvg(SimpleXMLElement $svg): array
    {
        $colors = [];

        $colors = array_merge($colors, $this->extractColorsFromAttributes($svg));
        $colors = array_merge($colors, $this->extractColorsFromStyles($svg));

        return array_unique($colors);
    }

    /**
     * Recursively extracts colors from 'fill' and 'stroke' attributes of SVG elements.
     *
     * @return string[]
     */
    private function extractColorsFromAttributes(SimpleXMLElement $element): array
    {
        $colors = [];

        if (isset($element['fill'])) {
            $colors[] = (string)$element['fill'];
        }

        if (isset($element['stroke'])) {
            $colors[] = (string)$element['stroke'];
        }

        foreach ($element->children() as $child) {
            $colors = array_merge($colors, $this->extractColorsFromAttributes($child));
        }

        return $colors;
    }

    /**
     * Extracts colors from CSS styles within <style> tags.
     *
     * @return string[]
     */
    private function extractColorsFromStyles(SimpleXMLElement $svg): array
    {
        $colors = [];

        $styleElements = $svg->xpath('//*[local-name()="style"]');
        foreach ($styleElements as $styleElement) {
            $styleContent = (string) $styleElement;

            preg_match_all('/fill\s*:\s*(#[0-9a-fA-F]{3,6}|[a-zA-Z]+);/', $styleContent, $fillMatches);
            foreach ($fillMatches[1] as $color) {
                $colors[] = $color;
            }

            preg_match_all('/stroke\s*:\s*(#[0-9a-fA-F]{3,6}|[a-zA-Z]+);/', $styleContent, $strokeMatches);
            foreach ($strokeMatches[1] as $color) {
                $colors[] = $color;
            }
        }

        return $colors;
    }
}
