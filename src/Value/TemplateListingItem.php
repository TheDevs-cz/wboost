<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateCategory;
use WBoost\Web\Entity\TemplateDocument;

/**
 * One card of the Šablony listing: a template or a multi-page document.
 */
final readonly class TemplateListingItem
{
    private function __construct(
        public null|Template $template,
        public null|TemplateDocument $document,
    ) {
    }

    public static function template(Template $template): self
    {
        return new self($template, null);
    }

    public static function document(TemplateDocument $document): self
    {
        return new self(null, $document);
    }

    public function position(): int
    {
        return $this->template->position ?? $this->document->position ?? 0;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->template->createdAt ?? $this->document->createdAt ?? new \DateTimeImmutable('@0');
    }

    public function category(): null|TemplateCategory
    {
        return $this->template !== null ? $this->template->category : $this->document?->category;
    }
}
