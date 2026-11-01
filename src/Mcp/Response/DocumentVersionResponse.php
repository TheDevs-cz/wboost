<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * One stored version of a document fill (an export, or a saved draft).
 */
readonly final class DocumentVersionResponse
{
    /**
     * @param array{texts: list<array{label: string, value: string}>, pictures: int, hidden: int} $summary
     * @param null|array{fields: mixed, pages: mixed} $fill
     */
    public function __construct(
        public string $id,
        /** User label; null = unnamed (refer to it by lastExportedAt). */
        public null|string $name,
        public bool $pinned,
        /** Saved work in progress ("Rozpracováno"), never exported. */
        public bool $draft,
        /** ISO 8601. */
        public string $lastExportedAt,
        public int $exportCount,
        /** web | api | mcp — of the latest export / save. */
        public string $channel,
        /** Labelled texts, picture + hidden counts — tells versions apart without a render. */
        public array $summary,
        /** Opens the web fill page seeded from this version. */
        public string $loadUrl,
        /** "get" / "save_draft" only: the fill in export_document's own shape — pass `fields` + `pages` back, or `versionId`. */
        public null|array $fill = null,
    ) {
    }
}
