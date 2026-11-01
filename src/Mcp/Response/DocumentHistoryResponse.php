<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * `document_history` answer: the document's versions (pinned first, then
 * freshest) — or, for a single-version action, that one version with its fill.
 */
readonly final class DocumentHistoryResponse
{
    /**
     * @param list<DocumentVersionResponse> $versions
     * @param list<string> $warnings
     */
    public function __construct(
        public string $documentId,
        public string $documentName,
        public array $versions,
        public null|DocumentVersionResponse $version,
        public string $historyUrl,
        public array $warnings = [],
    ) {
    }
}
