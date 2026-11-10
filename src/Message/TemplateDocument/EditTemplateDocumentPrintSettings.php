<?php

declare(strict_types=1);

namespace WBoost\Web\Message\TemplateDocument;

use Ramsey\Uuid\UuidInterface;

/**
 * Bleed + crop marks for the document AND every page variant — one PDF never
 * mixes print setups.
 */
readonly final class EditTemplateDocumentPrintSettings
{
    public function __construct(
        public UuidInterface $documentId,
        public float $bleedMm,
        public bool $cropMarks,
    ) {
    }
}
