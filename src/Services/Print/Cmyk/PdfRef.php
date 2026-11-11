<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Print\Cmyk;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** An indirect reference (`12 0 R`); generation is always 0 in the files we read. */
#[Exclude]
readonly final class PdfRef
{
    public function __construct(
        public int $number,
    ) {
    }
}
