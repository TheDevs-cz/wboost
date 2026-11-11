<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

/**
 * The CMYK colour pass over a PDF ({@see \WBoost\Web\Services\Print\Cmyk\PdfCmykConverter})
 * met something it has no rule for — an operator, a colour space, an image
 * encoding — or the finished file still holds an RGB colour
 * ({@see \WBoost\Web\Services\Print\Cmyk\PdfRgbVerifier}). A CMYK file that
 * is wrong about colour is worse than none, so the export fails instead.
 */
final class CmykConversionFailed extends \RuntimeException
{
}
