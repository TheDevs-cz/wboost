<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

/**
 * Graph error 4/17/32/613 or subcode 2207042: API rate limit — Instagram
 * caps accounts at 100 API-published posts per rolling 24 h.
 */
final class InstagramRateLimited extends MetaApiError
{
    public function userMessage(): string
    {
        return 'Instagram teď nepřijímá další příspěvky (denní limit). Zkuste to prosím později.';
    }
}
