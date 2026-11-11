<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * The calendar model could not be run on the server: `node` is missing or
 * too old, the process timed out, or it answered something unreadable.
 * Nothing about the request is wrong — the same call can be retried.
 */
#[WithHttpStatus(Response::HTTP_SERVICE_UNAVAILABLE)]
final class CalendarModelUnavailable extends \RuntimeException
{
}
