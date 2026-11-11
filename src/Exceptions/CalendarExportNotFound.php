<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/** Unknown export id — or one that belongs to another calendar (the same answer on purpose). */
#[WithHttpStatus(Response::HTTP_NOT_FOUND)]
final class CalendarExportNotFound extends \Exception
{
}
