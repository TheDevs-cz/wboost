<?php

declare(strict_types=1);

namespace WBoost\Web\Message\TemplateDocument;

use Ramsey\Uuid\UuidInterface;

/**
 * Give a stored document version a user-facing name (null / blank = back to
 * the export-date label) — {@see \WBoost\Web\Message\Template\RenameTemplateExportVersion}
 * for a multi-page document's history.
 */
readonly final class RenameTemplateDocumentExportVersion
{
    public function __construct(
        public UuidInterface $versionId,
        public null|string $name,
    ) {
    }
}
