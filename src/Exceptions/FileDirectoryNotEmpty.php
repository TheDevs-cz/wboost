<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * Raised when a folder delete is attempted while the folder still contains
 * images or sub-folders. The gallery refuses to delete a non-empty folder so
 * its contents are never silently relocated or discarded — the user must empty
 * it first. The {@see \WBoost\Web\Twig\Components\Project\ImageGallery}
 * component pre-checks emptiness and shows a friendly message, so this is only
 * the server-side safety net (e.g. a concurrent upload between the check and
 * the delete).
 */
#[WithHttpStatus(Response::HTTP_CONFLICT)]
final class FileDirectoryNotEmpty extends \Exception
{
}
