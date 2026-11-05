<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/** No such colour in that manual ("Barvy v manuálech", F7 P3). */
#[WithHttpStatus(Response::HTTP_NOT_FOUND)]
final class ManualPaletteColorNotFound extends \Exception
{
}
