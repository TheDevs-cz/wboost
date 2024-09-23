<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

readonly final class Logo
{
    public function __construct(
        public null|SvgImage $horizontal,
        public null|SvgImage $vertical,
        public null|SvgImage $horizontalWithClaim,
        public null|SvgImage $verticalWithClaim,
        public null|SvgImage $symbol,
    ) {
    }

    public static function withoutImages(): self
    {
        return new self(null, null, null, null, null);
    }

    public function variant(LogoTypeVariant $variant): null|SvgImage
    {
        return match ($variant) {
            LogoTypeVariant::Horizontal => $this->horizontal,
            LogoTypeVariant::HorizontalWithClaim => $this->horizontalWithClaim,
            LogoTypeVariant::Vertical => $this->vertical,
            LogoTypeVariant::VerticalWithClaim => $this->horizontalWithClaim,
            LogoTypeVariant::Symbol => $this->symbol,
        };
    }

    public function imagesCount(): int
    {
        $logos = array_filter([
            $this->horizontal,
            $this->vertical,
            $this->horizontalWithClaim,
            $this->verticalWithClaim,
            $this->symbol,
        ]);

        return count($logos);
    }

    public function introImage(): null|SvgImage
    {
        $images = array_values(array_filter([
            $this->horizontal,
            $this->horizontalWithClaim,
            $this->symbol,
            $this->vertical,
            $this->verticalWithClaim,
        ]));

        return $images[0] ?? null;
    }

    /**
     * @return array<Color>
     */
    public function getDetectedColors(): array
    {
        $detectedColors = [];

        $images = array_filter([
            $this->horizontal,
            $this->horizontalWithClaim,
            $this->symbol,
            $this->vertical,
            $this->verticalWithClaim,
        ]);

        foreach ($images as $image) {
            array_push($detectedColors, ...$image->detectedColors);
        }

        $detectedColors = array_unique($detectedColors);

        return array_map(fn (string $hex): Color => new Color($hex), $detectedColors);
    }
}
