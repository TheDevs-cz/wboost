<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

/**
 * One manual using a palette colour — see {@see GetProjectPaletteUsage}.
 */
readonly final class PaletteColorUsageRow
{
    public function __construct(
        public string $manualId,
        public string $manualName,
        /** The manual's logo carries the colour ("Z logotypu" — HEX locked, not deletable). */
        public bool $fromLogo,
    ) {
    }
}
