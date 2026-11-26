<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * Where a published picture lands. Feed posts accept aspect ratios between
 * 4:5 and 1.91:1 (1:1, 4:5 templates); a 9:16 template goes out as a story,
 * which carries no caption.
 */
enum InstagramPostKind: string
{
    case Feed = 'feed';
    case Story = 'story';

    public static function forPreset(DimensionPreset $preset): self
    {
        return $preset === DimensionPreset::InstagramStory ? self::Story : self::Feed;
    }

    public function supportsCaption(): bool
    {
        return $this === self::Feed;
    }
}
