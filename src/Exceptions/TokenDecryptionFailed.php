<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

/**
 * A stored social access token could not be decrypted — usually the
 * SOCIAL_TOKEN_ENCRYPTION_KEY was rotated. Treated as "needs reconnect".
 */
final class TokenDecryptionFailed extends \Exception
{
}
