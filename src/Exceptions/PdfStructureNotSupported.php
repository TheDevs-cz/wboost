<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

/**
 * {@see \WBoost\Web\Services\Print\PdfPageBoxes} met a PDF it does not read
 * (a cross-reference stream, an earlier incremental update, encryption…).
 * The file was left untouched.
 */
final class PdfStructureNotSupported extends \RuntimeException
{
}
