<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

/**
 * The Facebook account being connected is already linked to a DIFFERENT app
 * user — refuse instead of silently re-linking (that would let one user steal
 * another user's publishing connection).
 */
final class SocialAccountAlreadyLinked extends \Exception
{
}
