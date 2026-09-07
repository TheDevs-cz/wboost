<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Template;

use Ramsey\Uuid\UuidInterface;

/**
 * Pin (or unpin) a stored export version: pinned versions sort first on every
 * history surface and are exempt from the per-surface pruning cap.
 */
readonly final class PinTemplateExportVersion
{
    public function __construct(
        public UuidInterface $versionId,
        public bool $pinned,
    ) {
    }
}
