<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * Social-network dimension presets. A preset is nothing more than a fixed
 * pixel size a {@see TemplateDimension} can carry as its origin marker —
 * the marker keeps the compact ratio label ("1:1") and identifies variants
 * that can be published straight to Facebook/Instagram.
 */
enum DimensionPreset: string
{
    case InstagramPost = '1:1';
    case InstagramPortrait = '4:5';
    case InstagramStory = '9:16';

    public function label(): string
    {
        return match($this) {
            self::InstagramPost => 'Instagram příspěvek (1:1)',
            self::InstagramPortrait => 'Instagram portrét (4:5)',
            self::InstagramStory => 'Instagram story (9:16)',
        };
    }

    public function width(): int
    {
        return match($this) {
            self::InstagramPost => 1080,
            self::InstagramPortrait => 1080,
            self::InstagramStory => 1080,
        };
    }

    public function height(): int
    {
        return match($this) {
            self::InstagramPost => 1080,
            self::InstagramPortrait => 1350,
            self::InstagramStory => 1920,
        };
    }
}
