<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

enum TemplateDimension: string
{
    case InstagramPost = '1:1';
    case InstagramPortrait = '4:5';
    case InstagramStory = '9:16';

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
