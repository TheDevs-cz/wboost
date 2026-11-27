<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

/**
 * Graph 4 / 17 / 32 / 613 / 368 on a project Page call.
 */
final class FacebookPageRateLimited extends MetaApiError
{
    public function userMessage(): string
    {
        return 'Facebook teď nepřijímá další příspěvky. Zkuste to prosím později.';
    }
}
