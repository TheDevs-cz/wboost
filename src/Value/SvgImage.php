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
        /** Logo size in the manual, % of its default size (10–200, {@see LogoScale}); null = default. */
        public null|int $displayWidth = null,
        /**
         * The variant gets a page of its OWN in the manual, showing all of its
         * colour variants, instead of sharing a page with another variant.
         */
        public bool $ownPage = false,
    ) {
    }

    /**
     * @param array{
     *     filePath: string,
     *     detectedColors: array<string>,
     *     colorsMapping?: array<string, array{background: null|string, colors: array<string, string>, opacities?: array<string, int>}>,
     *     widthInfo?: null|string,
     *     heightInfo?: null|string,
     *     displayWidth?: null|int,
     *     ownPage?: null|bool,
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
            displayWidth: $data['displayWidth'] ?? null,
            ownPage: ($data['ownPage'] ?? false) === true,
        );
    }

    /**
     * @return array{
     *     filePath: string,
     *     detectedColors: array<string>,
     *     colorsMapping: array<string, array{background: null|string, colors: array<string, string>, opacities: array<string, int>}>,
     *     widthInfo: null|string,
     *     heightInfo: null|string,
     *     displayWidth: null|int,
     *     ownPage: bool,
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
            'displayWidth' => $this->displayWidth,
            'ownPage' => $this->ownPage,
        ];
    }

    public function getColorsMapping(LogoColorVariant $colorVariant): null|ColorMapping
    {
        return $this->colorsMapping[$colorVariant->value] ?? null;
    }

    /**
     * @param array<string, string> $colors
     * @param array<string, int> $opacities percent per FROM colour; 100 / absent = opaque
     */
    public function updateColorsMapping(LogoColorVariant $colorVariant, null|string $background, array $colors, array $opacities = []): void
    {
        $this->colorsMapping[$colorVariant->value] = new ColorMapping($background, $colors, $opacities);
    }

    /**
     * `--logo-ratio` (width / height) for a manual card showing this logo as
     * `$svgDataUri` (what `remap_svg_colors()` returns) — the protective-zone
     * card sizes its zone from it. null = unknown. {@see LogoAspectRatio}
     */
    public function cssAspectRatio(string $svgDataUri): null|string
    {
        return LogoAspectRatio::cssValueOfDataUri($svgDataUri);
    }

    public function updateDimensionsInfo(null|string $width, null|string $height): void
    {
        $this->widthInfo = $width;
        $this->heightInfo = $height;
    }

    /**
     * Logo size in the manual, % of its DEFAULT size in each card
     * ({@see LogoScale}): 10–200 %, 100 % = výchozí. null / 0 / negative =
     * no value; out-of-range values are clamped into 10–200. 100 is stored
     * as null — at the variant level it IS the default.
     */
    public function updateDisplayWidth(null|int $displayWidth): void
    {
        $displayWidth = LogoScale::normalize($displayWidth);

        // 100 % IS the default — nothing to store at the variant level.
        $this->displayWidth = $displayWidth === LogoScale::DEFAULT ? null : $displayWidth;
    }

    public function updateOwnPage(bool $ownPage): void
    {
        $this->ownPage = $ownPage;
    }
}
