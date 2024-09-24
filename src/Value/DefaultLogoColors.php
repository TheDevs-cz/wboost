<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use WBoost\Web\Entity\Manual;
use WBoost\Web\Exceptions\MissingManualColor;

readonly final class DefaultLogoColors
{
    public static function background(LogoTypeVariant $typeVariant, LogoColorVariant $colorVariant, Manual $manual): string
    {
        $color1 = self::getManualColorHex($manual, 1);
        $color2 = self::getManualColorHex($manual, 2);
        $color3 = self::getManualColorHex($manual, 3);

        return match ($colorVariant) {
            LogoColorVariant::DarkBackground => $color1,
            LogoColorVariant::LightBackground => $color2,
            LogoColorVariant::OneColor => count($manual->detectedColors()) === 2 ? $color1 : $color3,
            LogoColorVariant::WhiteBackground => 'ffffff',
            LogoColorVariant::BlackBackground => '000000',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function mapping(LogoTypeVariant $typeVariant, LogoColorVariant $colorVariant, Manual $manual): array
    {
        $color1 = self::getManualColorHex($manual, 1);
        $color2 = self::getManualColorHex($manual, 2);
        $color3 = self::getManualColorHex($manual, 3);

        if ($colorVariant === LogoColorVariant::WhiteBackground) {
            return [
                $color1 => '000000',
                $color2 => '000000',
                $color3 => '000000',
            ];
        }

        if ($colorVariant === LogoColorVariant::BlackBackground) {
            return [
                $color1 => 'ffffff',
                $color2 => 'ffffff',
                $color3 => 'ffffff',
            ];
        }

        return match(count($manual->detectedColors())) {
            2 => match($typeVariant) {
                LogoTypeVariant::Horizontal => match ($colorVariant) {
                    LogoColorVariant::DarkBackground => [
                        $color2 => 'ffffff',
                    ],
                    LogoColorVariant::LightBackground => [
                        $color1 => 'ffffff',
                        $color2 => 'ffffff',
                    ],
                    LogoColorVariant::OneColor => [
                        $color1 => 'ffffff',
                        $color2 => 'ffffff',
                    ],
                },
                LogoTypeVariant::HorizontalWithClaim => match ($colorVariant) {
                    LogoColorVariant::DarkBackground => [
                        $color2 => 'ffffff',
                    ],
                    LogoColorVariant::LightBackground => [
                        $color1 => 'ffffff',
                        $color2 => 'ffffff',
                    ],
                    LogoColorVariant::OneColor => [
                        $color1 => 'ffffff',
                        $color2 => 'ffffff',
                    ],
                },
                LogoTypeVariant::Vertical => match ($colorVariant) {
                    LogoColorVariant::DarkBackground => [
                        $color2 => 'ffffff',
                    ],
                    LogoColorVariant::LightBackground => [
                        $color1 => 'ffffff',
                        $color2 => 'ffffff',
                    ],
                    LogoColorVariant::OneColor => [
                        $color1 => 'ffffff',
                        $color2 => 'ffffff',
                    ],
                },
                LogoTypeVariant::VerticalWithClaim => match ($colorVariant) {
                    LogoColorVariant::DarkBackground => [
                        $color2 => 'ffffff',
                    ],
                    LogoColorVariant::LightBackground => [
                        $color1 => 'ffffff',
                        $color2 => 'ffffff',
                    ],
                    LogoColorVariant::OneColor => [
                        $color1 => 'ffffff',
                        $color2 => 'ffffff',
                    ],
                },
                LogoTypeVariant::Symbol => match ($colorVariant) {
                    LogoColorVariant::DarkBackground => [
                        $color1 => 'ffffff',
                    ],
                    LogoColorVariant::LightBackground => [
                        $color1 => 'ffffff',
                    ],
                    LogoColorVariant::OneColor => [],
                },
            },

            default => match($typeVariant) {
                LogoTypeVariant::Horizontal => match ($colorVariant) {
                    LogoColorVariant::DarkBackground => [
                        $color1 => 'ffffff',
                    ],
                    LogoColorVariant::LightBackground => [
                        $color3 => 'ffffff',
                    ],
                    LogoColorVariant::OneColor => [
                        $color1 => 'ffffff',
                        $color2 => $color3,
                    ],
                },
                LogoTypeVariant::HorizontalWithClaim => match ($colorVariant) {
                    LogoColorVariant::DarkBackground => [
                        $color1 => 'ffffff',
                    ],
                    LogoColorVariant::LightBackground => [
                        $color2 => 'ffffff',
                        $color3 => 'ffffff',
                    ],
                    LogoColorVariant::OneColor => [
                        $color1 => 'ffffff',
                        $color2 => $color3,
                        $color3 => 'ffffff',
                    ],
                },

                LogoTypeVariant::Vertical => match ($colorVariant) {
                    LogoColorVariant::DarkBackground => [
                        $color1 => 'ffffff',
                    ],
                    LogoColorVariant::LightBackground => [
                        $color2 => 'ffffff',
                    ],
                    LogoColorVariant::OneColor => [
                        $color1 => 'ffffff',
                        $color2 => 'ffffff',
                    ],
                },
                LogoTypeVariant::VerticalWithClaim => match ($colorVariant) {
                    LogoColorVariant::DarkBackground => [
                        $color1 => 'ffffff',
                        $color3 => $color2,
                    ],
                    LogoColorVariant::LightBackground => [
                        $color1 => 'ffffff',
                        $color2 => 'ffffff',
                    ],
                    LogoColorVariant::OneColor => [
                        $color1 => 'ffffff',
                        $color2 => 'ffffff',
                        $color3 => 'ffffff',
                    ],
                },
                LogoTypeVariant::Symbol => match ($colorVariant) {
                    LogoColorVariant::DarkBackground => [
                        $color1 => 'ffffff',
                    ],
                    LogoColorVariant::LightBackground => [
                        $color3 => 'ffffff',
                    ],
                    LogoColorVariant::OneColor => [],
                },
            },
        };
    }

    private static function getManualColorHex(Manual $manual, int $color): string
    {
        try {
            return $manual->color($color)->hex;
        } catch (MissingManualColor) {
            return '000000';
        }
    }
}
