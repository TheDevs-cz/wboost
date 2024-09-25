<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Manual;

use Ramsey\Uuid\UuidInterface;

readonly final class DeleteManualFont
{
    public function __construct(
        public UuidInterface $manualFontId,
    ) {
    }
}
