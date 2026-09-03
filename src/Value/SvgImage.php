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
        /** Logo width in the manual preview, as a percentage of the card (1-100); null = default. */
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
     *     colorsMapping?: array<string, array{background: null|string, colors: array<string, string>}>,
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
     *     colorsMapping: array<string, array{background: null|string, colors: array<string, string>}>,
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

    /**
     * Percentage width of the logo inside its manual-preview card (1-100).
     * 0, null or out-of-range values reset to default (null = no override).
     */
    public function updateDisplayWidth(null|int $displayWidth): void
    {
        $this->displayWidth = ($displayWidth !== null && $displayWidth > 0)
            ? min($displayWidth, 100)
            : null;
    }

    public function updateOwnPage(bool $ownPage): void
    {
        $this->ownPage = $ownPage;
    }
}
