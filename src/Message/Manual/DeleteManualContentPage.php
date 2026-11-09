<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Manual;

use Ramsey\Uuid\UuidInterface;

/**
 * Deletes a CUSTOM content page. The intro cannot be deleted.
 */
readonly final class DeleteManualContentPage
{
    public function __construct(
        public UuidInterface $pageId,
    ) {
    }
}
