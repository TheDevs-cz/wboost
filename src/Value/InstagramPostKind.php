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

    /** Feed posts accept width / height between 4:5 and 1.91:1. */
    public const float MIN_FEED_RATIO = 0.8;
    public const float MAX_FEED_RATIO = 1.91;

    public static function forPreset(DimensionPreset $preset): self
    {
        return $preset === DimensionPreset::InstagramStory ? self::Story : self::Feed;
    }

    public function supportsCaption(): bool
    {
        return $this === self::Feed;
    }
}
