<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

#[WithHttpStatus(404)]
final class AiScanNotFound extends \Exception
{
}
