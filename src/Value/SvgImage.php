<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use JetBrains\PhpStorm\Immutable;

final class SvgImage
{
    public function __construct(
        readonly public string $filePath,
        /** @var array<string> */
        readonly public array $detectedColors,
        /** @var array<string, ColorMapping> */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        public array $colorsMapping = [],
        public null|string $widthInfo = null,
        public null|string $heightInfo = null,
    ) {
    }

    /**
     * @param array{
     *     filePath: string,
     *     detectedColors: array<string>,
     *     colorsMapping?: array<string, array{background: null|string, colors: array<string, string>}>,
     *     widthInfo?: null|string,
     *     heightInfo?: null|string,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $colorMapping = [];

        foreach ($data['colorsMapping'] ?? [] as $colorVariant => $mapping) {
            $colorMapping[$colorVariant] = ColorMapping::fromArray($mapping);
        }

        return new self(
            filePath: $data['filePath'],
            detectedColors: $data['detectedColors'],
            colorsMapping: $colorMapping,
            widthInfo: $data['widthInfo'] ?? null,
            heightInfo: $data['heightInfo'] ?? null,
        );
    }

    /**
     * @return array{
     *     filePath: string,
     *     detectedColors: array<string>,
     *     colorsMapping: array<string, array{background: null|string, colors: array<string, string>}>,
     *     widthInfo: null|string,
     *     heightInfo: null|string,
     *   }
     */
    public function toArray(): array
    {
        $colorMapping = [];

        foreach ($this->colorsMapping as $colorVariant => $mapping) {
            $colorMapping[$colorVariant] = $mapping->toArray();
        }

        return [
            'filePath' => $this->filePath,
            'detectedColors' => $this->detectedColors,
            'colorsMapping' => $colorMapping,
            'widthInfo' => $this->widthInfo,
            'heightInfo' => $this->heightInfo,
        ];
    }

    public function getColorsMapping(LogoColorVariant $colorVariant): null|ColorMapping
    {
        return $this->colorsMapping[$colorVariant->value] ?? null;
    }

    /**
     * @param array<string, string> $colors
     */
    public function updateColorsMapping(LogoColorVariant $colorVariant, null|string $background, array $colors): void
    {
        $this->colorsMapping[$colorVariant->value] = new ColorMapping($background, $colors);
    }

    public function updateDimensionsInfo(null|string $width, null|string $height): void
    {
        $this->widthInfo = $width;
        $this->heightInfo = $height;
    }
}
