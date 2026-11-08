<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * Social-network dimension presets. A preset is nothing more than a fixed
 * pixel size a {@see TemplateDimension} can carry as its origin marker —
 * the marker keeps the compact ratio label ("1:1") and identifies variants
 * that can be published straight to Facebook/Instagram.
 *
 * 1:1 (1080×1080) is RETIRED for new formats since 2026-11-08: no picker,
 * wizard, form post or document API offers it any more ({@see offered()}).
 * The case stays because existing variants carry it (87 on prod at the
 * time) and must keep their label, publish gating and rendering. A square
 * size is still possible as a free-form px dimension (preset NULL).
 */
enum DimensionPreset: string
{
    case InstagramPost = '1:1';
    case InstagramPortrait = '4:5';
    case InstagramStory = '9:16';

    /**
     * Presets a NEW dimension may be created with (add template / variant /
     * group dimension / group wizard / document), in display order.
     *
     * @return list<self>
     */
    public static function offered(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $preset): bool => $preset->isOffered()));
    }

    public function isOffered(): bool
    {
        return $this !== self::InstagramPost;
    }

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
