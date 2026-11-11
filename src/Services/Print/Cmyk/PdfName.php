<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Print\Cmyk;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** A PDF name object (`/DeviceRGB`) — the text after the slash, as written. */
#[Exclude]
readonly final class PdfName
{
    public function __construct(
        public string $value,
    ) {
    }
}
