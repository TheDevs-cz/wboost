<?php

declare(strict_types=1);

namespace WBoost\Web\Message\TemplateDocument;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\DocumentFillValues;
use WBoost\Web\Value\ExportChannel;

readonly final class RecordTemplateDocumentExportVersion
{
    public function __construct(
        public UuidInterface $documentId,
        public null|UuidInterface $exportedByUserId,
        public ExportChannel $channel,
        public DocumentFillValues $fill,
        public null|string $draftVersionId = null,
    ) {
    }
}
