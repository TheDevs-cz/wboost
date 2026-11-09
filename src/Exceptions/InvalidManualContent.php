<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * A manual content page payload (segments, elements, ProseMirror rich text)
 * that does not match the strict shape the server accepts. The message is
 * a developer-facing reason — the editor never sends such a payload itself.
 */
#[WithHttpStatus(Response::HTTP_UNPROCESSABLE_ENTITY)]
final class InvalidManualContent extends \Exception
{
}
