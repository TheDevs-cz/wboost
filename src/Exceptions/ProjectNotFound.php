<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ProjectNotFound extends NotFoundHttpException
{
}
