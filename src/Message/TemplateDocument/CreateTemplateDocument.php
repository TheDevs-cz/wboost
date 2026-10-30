<?php

declare(strict_types=1);

namespace WBoost\Web\Message\TemplateDocument;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\TemplateDimension;

readonly final class CreateTemplateDocument
{
    /**
     * @param list<UuidInterface> $templateIds existing templates to attach as the first pages, in order
     */
    public function __construct(
        public UuidInterface $documentId,
        public UuidInterface $projectId,
        public string $name,
        public null|UuidInterface $categoryId,
        public TemplateDimension $dimension,
        public array $templateIds = [],
    ) {
    }
}
