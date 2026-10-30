<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

readonly final class ExportDocumentResponse
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public string $documentId,
        public string $documentName,
        public string $projectName,
        /** `application/pdf` or `application/zip`. */
        public string $format,
        public int $pageCount,
        public int $sizeBytes,
        public string $fileName,
        public array $warnings,
    ) {
    }
}
