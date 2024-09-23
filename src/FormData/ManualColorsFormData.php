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
            $detectedColors[] = new ManualColorFormData($detectedColor->color->hex, $index, $detectedColor->type?->value);
        }

        foreach ($manual->customColors as $index => $customColor) {
            $customColors[] = new ManualColorFormData($customColor->color->hex, $index, $customColor->type?->value);
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
