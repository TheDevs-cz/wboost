<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * How a tracked export was triggered — a logged-in user downloading a PNG from
 * the web fill page, a service-to-service call through the OAuth2 API, or a
 * direct publish to a connected social network.
 */
enum ExportChannel: string
{
    case Web = 'web';
    case Api = 'api';
    case Facebook = 'facebook';
    case Instagram = 'instagram';

    public function label(): string
    {
        return match ($this) {
            self::Web => 'Web',
            self::Api => 'API',
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
        };
    }
}
