<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Manual;

use Ramsey\Uuid\UuidInterface;

readonly final class DisableManualFont
{
    public function __construct(
        public UuidInterface $manualId,
        public UuidInterface $fontId,
    ) {
    }
}
