<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * How a tracked export was triggered — a logged-in user downloading a PNG from
 * the web fill page, or a service-to-service call through the OAuth2 API.
 */
enum ExportChannel: string
{
    case Web = 'web';
    case Api = 'api';

    public function label(): string
    {
        return match ($this) {
            self::Web => 'Web',
            self::Api => 'API',
        };
    }
}
