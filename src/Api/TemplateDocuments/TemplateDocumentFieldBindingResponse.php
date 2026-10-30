<?php

declare(strict_types=1);

namespace WBoost\Web\Api\TemplateDocuments;

final readonly class TemplateDocumentFieldBindingResponse
{
    public function __construct(
        public string $pageId,
        public string $templateId,
        /** The input on that page (`variant.inputs[].id` / `variant.imageInputs[].id`). */
        public string $inputId,
        /** false = the input no longer exists on the page (deleted in the editor) — ignored when filling. */
        public bool $valid,
    ) {
    }
}
