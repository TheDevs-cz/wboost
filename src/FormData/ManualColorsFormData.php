<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use WBoost\Web\Entity\Manual;
use WBoost\Web\Value\Color;
use WBoost\Web\Value\ManualColor;
use WBoost\Web\Value\ManualColorType;

final class ManualColorsFormData
{
    public function __construct(
        /** @var array<ManualColorFormData> */
        public array $detectedColors,

        /** @var array<null|ManualColorFormData> */
        public array $customColors,
    ) {
    }

    public static function fromManual(Manual $manual): self
    {
        $detectedColors = [];
        $customColors = [];

        foreach ($manual->detectedColors() as $index => $detectedColor) {
            $detectedColors[] = new ManualColorFormData(
                color: $detectedColor->color->hex,
                order: $index,
                type: $detectedColor->type?->value,
                c: $detectedColor->cmyk[0] ?? null,
                m: $detectedColor->cmyk[1] ?? null,
                y: $detectedColor->cmyk[2] ?? null,
                k: $detectedColor->cmyk[3] ?? null,
                pantone: $detectedColor->pantone,
            );
        }

        foreach ($manual->customColors as $index => $customColor) {
            $customColors[] = new ManualColorFormData(
                color: $customColor->color->hex,
                order: $index,
                type: $customColor->type?->value,
                c: $customColor->pantone,
                m: $customColor->cmyk[0] ?? null,
                y: $customColor->cmyk[1] ?? null,
                k: $customColor->cmyk[2] ?? null,
                pantone: $customColor->cmyk[3] ?? null,
            );
        }

        return new self($detectedColors, $customColors);
    }

    /**
     * @return array<ManualColor>
     */
    public function manualDetectedColors(): array
    {
        $manualColors = [];

        foreach ($this->detectedColors as $detectedColor) {
            $order = $detectedColor->order;

            $manualColor = new ManualColor(
                new Color($detectedColor->color),
                $detectedColor->type ? ManualColorType::tryFrom($detectedColor->type) : null,
                $detectedColor->pantone,
                [$detectedColor->c, $detectedColor->m, $detectedColor->y, $detectedColor->k],
            );

            if ($order !== null) {
                $manualColors[$order] = $manualColor;
            } else {
                $manualColors[] = $manualColor;
            }
        }

        ksort($manualColors);

        return $manualColors;
    }

    /**
     * @return array<ManualColor>
     */
    public function manualCustomColors(): array
    {
        $manualColors = [];

        foreach ($this->customColors as $customColor) {
            if ($customColor === null) {
                continue;
            }

            $order = $customColor->order;

            $manualColor = new ManualColor(
                new Color($customColor->color),
                $customColor->type ? ManualColorType::tryFrom($customColor->type) : null,
                $customColor->pantone,
                [$customColor->c, $customColor->m, $customColor->y, $customColor->k],
            );

            if ($order !== null) {
                $manualColors[$order] = $manualColor;
            } else {
                $manualColors[] = $manualColor;
            }
        }

        ksort($manualColors);

        return $manualColors;
    }
}
