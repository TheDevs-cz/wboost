<?php

declare(strict_types=1);

namespace WBoost\Web\Message\TemplateDocument;

use Ramsey\Uuid\UuidInterface;

/**
 * Pin (or unpin) a stored document version: pinned versions sort first and
 * are exempt from the pruning cap.
 */
readonly final class PinTemplateDocumentExportVersion
{
    public function __construct(
        public UuidInterface $versionId,
        public bool $pinned,
    ) {
    }
}
