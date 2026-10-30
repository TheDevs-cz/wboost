<?php

declare(strict_types=1);

namespace WBoost\Web\Api\TemplateDocuments;

/**
 * A shared field: ONE value (`fields[id]` in the export body) filled into
 * every bound input across the pages.
 */
final readonly class TemplateDocumentFieldResponse
{
    /**
     * @param list<TemplateDocumentFieldBindingResponse> $bindings
     */
    public function __construct(
        public string $id,
        public string $name,
        /** `text` (a plain string) | `image` (a gallery image id, or `{imageId, hide}`). */
        public string $kind,
        public array $bindings,
        /** The smallest `maxLength` of the bound text inputs (null = unlimited / image). */
        public null|int $maxLength,
    ) {
    }
}
