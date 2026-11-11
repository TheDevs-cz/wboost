<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * A client (link holder / read-only member) tried to change something only
 * the designer may: any design key, `finish`, or a content collection outside
 * the client whitelist. The whole request is refused (403).
 */
#[WithHttpStatus(Response::HTTP_FORBIDDEN)]
final class CalendarChangeForbidden extends \Exception
{
    public const string MESSAGE = 'Tuto změnu může udělat jen grafik.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
