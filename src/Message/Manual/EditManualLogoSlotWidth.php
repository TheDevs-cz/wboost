<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Manual;

use Ramsey\Uuid\UuidInterface;

readonly final class EditManualLogoSlotWidth
{
    public function __construct(
        public UuidInterface $manualId,
        /** The card's slot id — `<page>.<logoVariant>.<colorVariant|base>`. */
        public string $slot,
        /** Null or 0 clears the card's own width, falling back to the variant's. */
        public null|int $displayWidth,
    ) {
    }
}
