<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Manual;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\ManualFontType;

readonly final class EditManualFont
{
    public function __construct(
        public UuidInterface $manualFontId,
        public UuidInterface $fontId,
        public ManualFontType $type,
        public null|string $color,
    ) {
    }
}
