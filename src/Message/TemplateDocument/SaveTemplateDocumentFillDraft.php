<?php

declare(strict_types=1);

namespace WBoost\Web\Message\TemplateDocument;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\DocumentFillValues;
use WBoost\Web\Value\ExportChannel;

/**
 * "Uložit rozpracované" on the document fill page: overwrite the page's own
 * draft (`versionId` when it is a draft of this document) or start a new one.
 */
readonly final class SaveTemplateDocumentFillDraft
{
    public function __construct(
        public UuidInterface $versionId,
        public UuidInterface $documentId,
        public null|UuidInterface $savedByUserId,
        public DocumentFillValues $fill,
        /** Who saved it — the web page, or an MCP agent (`document_history` "save_draft"). */
        public ExportChannel $channel = ExportChannel::Web,
    ) {
    }
}
