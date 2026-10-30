<?php

declare(strict_types=1);

namespace WBoost\Web\Message\TemplateDocument;

use Ramsey\Uuid\UuidInterface;

/**
 * The document's whole page list, in order — attach, detach and reorder in
 * one idempotent step. Templates left out are detached (they return to the
 * listing), new ones are attached.
 */
readonly final class SetTemplateDocumentPages
{
    /**
     * @param list<UuidInterface> $templateIds
     */
    public function __construct(
        public UuidInterface $documentId,
        public array $templateIds,
    ) {
    }
}
