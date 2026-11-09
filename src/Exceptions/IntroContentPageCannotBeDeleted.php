<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * The intro is the manual's first page and exists at most once — it is
 * emptied in its editor (no content → no page), never deleted.
 */
#[WithHttpStatus(Response::HTTP_BAD_REQUEST)]
final class IntroContentPageCannotBeDeleted extends \Exception
{
}
