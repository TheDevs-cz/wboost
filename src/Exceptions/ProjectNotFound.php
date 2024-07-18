<?php

declare(strict_types=1);

namespace BrandManuals\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ProjectNotFound extends NotFoundHttpException
{
}
