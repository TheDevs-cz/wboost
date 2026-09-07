<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Template;

use Ramsey\Uuid\UuidInterface;

/**
 * Give a stored export version a user-facing name (null / blank = back to the
 * export-date label). Shared per template — everyone filling the surface sees
 * the same name.
 */
readonly final class RenameTemplateExportVersion
{
    public function __construct(
        public UuidInterface $versionId,
        public null|string $name,
    ) {
    }
}
