<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

/**
 * Publishing was attempted without a usable Facebook connection — no
 * SocialAccount row, it's flagged needsReconnect, or its stored token can't
 * be decrypted anymore.
 */
final class FacebookNotConnected extends \Exception
{
    public function userMessage(): string
    {
        return 'Nejprve propojte svůj facebookový účet ve svém profilu.';
    }
}
