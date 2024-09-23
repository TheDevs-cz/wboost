<?php /** @noinspection PhpUncoveredEnumCasesInspection */

declare(strict_types=1);

namespace WBoost\Web\Value;

use WBoost\Web\Entity\Manual;
use WBoost\Web\Exceptions\MissingManualColor;

readonly final class DefaultLogoColors
{
    /**
     * @throws MissingManualColor
     */
    public static function background(LogoTypeVariant $typeVariant, LogoColorVariant $colorVariant, Manual $manual): string
    {
        return match ($colorVariant) {
            LogoColorVariant::DarkBackground => $manual->color(1)->hex,
            LogoColorVariant::LightBackground => $manual->color(2)->hex,
            LogoColorVariant::OneColor => count($manual->detectedColors()) === 2 ? $manual->color(1)->hex : $manual->color(3)->hex,
            LogoColorVariant::WhiteBackground => 'ffffff',
            LogoColorVariant::BlackBackground => '000000',
        };
    }

    /**
     * @throws MissingManualColor
     * @return array<string, string>
     */
    public static function mapping(LogoTypeVariant $typeVariant, LogoColorVariant $colorVariant, Manual $manual): array
    {
        if ($colorVariant === LogoColorVariant::WhiteBackground) {
            return [
                $manual->color(1)->hex => '000000',
                $manual->color(2)->hex => '000000',
                $manual->color(3)->hex => '000000',
            ];
        }

        if ($colorVariant === LogoColorVariant::BlackBackground) {
            return [
                $manual->color(1)->hex => 'ffffff',
                $manual->color(2)->hex => 'ffffff',
                $manual->color(3)->hex => 'ffffff',
            ];
        }

        return match(count($manual->detectedColors())) {
            2 => match($typeVariant) {
                LogoTypeVariant::Horizontal => match ($colorVariant) {
                    LogoColorVariant::DarkBackground => [
                        $manual->color(2)->hex => 'ffffff',
                    ],
                    LogoColorVariant::LightBackground => [
                        $manual->color(1)->hex => 'ffffff',
                        $manual->color(2)->hex => 'ffffff',
                    ],
                    LogoColorVariant::OneColor => [
                        $manual->color(1)->hex => 'ffffff',
                        $manual->color(2)->hex => 'ffffff',
                    ],
                },
                LogoTypeVariant::HorizontalWithClaim => match ($colorVariant) {
                    LogoColorVariant::DarkBackground => [
                        $manual->color(2)->hex => 'ffffff',
                    ],
                    LogoColorVariant::LightBackground => [
                        $manual->color(1)->hex => 'ffffff',
                        $manual->color(2)->hex => 'ffffff',
                    ],
                    LogoColorVariant::OneColor => [
                        $manual->color(1)->hex => 'ffffff',
                        $manual->color(2)->hex => 'ffffff',
                    ],
                },
                LogoTypeVariant::Vertical => match ($colorVariant) {
                    LogoColorVariant::DarkBackground => [
                        $manual->color(2)->hex => 'ffffff',
                    ],
                    LogoColorVariant::LightBackground => [
                        $manual->color(1)->hex => 'ffffff',
                        $manual->color(2)->hex => 'ffffff',
                    ],
                    LogoColorVariant::OneColor => [
                        $manual->color(1)->hex => 'ffffff',
                        $manual->color(2)->hex => 'ffffff',
                    ],
                },
                LogoTypeVariant::VerticalWithClaim => match ($colorVariant) {
                    LogoColorVariant::DarkBackground => [
                        $manual->color(2)->hex => 'ffffff',
                    ],
                    LogoColorVariant::LightBackground => [
                        $manual->color(1)->hex => 'ffffff',
                        $manual->color(2)->hex => 'ffffff',
                    ],
                    LogoColorVariant::OneColor => [
                        $manual->color(1)->hex => 'ffffff',
                        $manual->color(2)->hex => 'ffffff',
                    ],
                },
                LogoTypeVariant::Symbol => match ($colorVariant) {
                    LogoColorVariant::DarkBackground => [
                        $manual->color(1)->hex => 'ffffff',
                    ],
                    LogoColorVariant::LightBackground => [
                        $manual->color(1)->hex => 'ffffff',
                    ],
                    LogoColorVariant::OneColor => [],
                },
            },

            default => match($typeVariant) {
                LogoTypeVariant::Horizontal => match ($colorVariant) {
                    LogoColorVariant::DarkBackground => [
                        $manual->color(1)->hex => 'ffffff',
                    ],
                    LogoColorVariant::LightBackground => [
                        $manual->color(3)->hex => 'ffffff',
                    ],
                    LogoColorVariant::OneColor => [
                        $manual->color(1)->hex => 'ffffff',
                        $manual->color(2)->hex => $manual->color(3)->hex,
                    ],
                },
                LogoTypeVariant::HorizontalWithClaim => match ($colorVariant) {
                    LogoColorVariant::DarkBackground => [
                        $manual->color(1)->hex => 'ffffff',
                    ],
                    LogoColorVariant::LightBackground => [
                        $manual->color(2)->hex => 'ffffff',
                        $manual->color(3)->hex => 'ffffff',
                    ],
                    LogoColorVariant::OneColor => [
                        $manual->color(1)->hex => 'ffffff',
                        $manual->color(2)->hex => $manual->color(3)->hex,
                        $manual->color(3)->hex => 'ffffff',
                    ],
                },

                LogoTypeVariant::Vertical => match ($colorVariant) {
                    LogoColorVariant::DarkBackground => [
                        $manual->color(1)->hex => 'ffffff',
                    ],
                    LogoColorVariant::LightBackground => [
                        $manual->color(2)->hex => 'ffffff',
                    ],
                    LogoColorVariant::OneColor => [
                        $manual->color(1)->hex => 'ffffff',
                        $manual->color(2)->hex => 'ffffff',
                    ],
                },
                LogoTypeVariant::VerticalWithClaim => match ($colorVariant) {
                    LogoColorVariant::DarkBackground => [
                        $manual->color(1)->hex => 'ffffff',
                        $manual->color(3)->hex => $manual->color(2)->hex,
                    ],
                    LogoColorVariant::LightBackground => [
                        $manual->color(1)->hex => 'ffffff',
                        $manual->color(2)->hex => 'ffffff',
                    ],
                    LogoColorVariant::OneColor => [
                        $manual->color(1)->hex => 'ffffff',
                        $manual->color(2)->hex => 'ffffff',
                        $manual->color(3)->hex => 'ffffff',
                    ],
                },
                LogoTypeVariant::Symbol => match ($colorVariant) {
                    LogoColorVariant::DarkBackground => [
                        $manual->color(1)->hex => 'ffffff',
                    ],
                    LogoColorVariant::LightBackground => [
                        $manual->color(3)->hex => 'ffffff',
                    ],
                    LogoColorVariant::OneColor => [],
                },
            },
        };
    }
}
