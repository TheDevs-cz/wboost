<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * One input a shared document field fills: the page (by its template id — a
 * template is a page of at most one document) + the input's id inside that
 * page's variant. Stored as JSON on the field; inputs live inside the
 * variant's JSONB, so there is no FK and a binding can go stale (the input
 * deleted in the editor) — readers skip what no longer resolves.
 */
final readonly class DocumentFieldBinding
{
    public function __construct(
        public string $templateId,
        public string $inputId,
    ) {
    }

    /**
     * @return array{templateId: string, inputId: string}
     */
    public function toArray(): array
    {
        return ['templateId' => $this->templateId, 'inputId' => $this->inputId];
    }

    public static function fromArray(mixed $data): null|self
    {
        if (!is_array($data) || !is_string($data['templateId'] ?? null) || !is_string($data['inputId'] ?? null)) {
            return null;
        }

        return new self($data['templateId'], $data['inputId']);
    }
}
