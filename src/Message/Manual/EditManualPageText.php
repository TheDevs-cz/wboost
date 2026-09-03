<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Manual;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\ManualPage;

readonly final class EditManualPageText
{
    public function __construct(
        public UuidInterface $manualId,
        public ManualPage $page,
        /** Null or blank restores the page's default heading. */
        public null|string $title,
        /** Null or blank restores the page's default description. */
        public null|string $description,
    ) {
    }
}
