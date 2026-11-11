<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * A calendar save / upload the server refuses because its shape or values
 * are not allowed. The MESSAGE IS THE CZECH TEXT SHOWN TO THE USER (the
 * endpoints answer 422 `{ error }` with it).
 */
#[WithHttpStatus(Response::HTTP_UNPROCESSABLE_ENTITY)]
final class InvalidCalendarChange extends \Exception
{
}
