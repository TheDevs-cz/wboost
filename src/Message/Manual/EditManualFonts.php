<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Manual;

use Ramsey\Uuid\UuidInterface;

readonly final class EditManualFonts
{
    public function __construct(
        public UuidInterface $manualId,
        public null|UuidInterface $primaryFontId,
        public null|UuidInterface $secondaryFontId,
    ) {
    }
}
