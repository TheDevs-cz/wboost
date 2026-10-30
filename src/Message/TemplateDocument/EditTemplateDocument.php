<?php

declare(strict_types=1);

namespace WBoost\Web\Message\TemplateDocument;

use Ramsey\Uuid\UuidInterface;

readonly final class EditTemplateDocument
{
    public function __construct(
        public UuidInterface $documentId,
        public string $name,
        public null|UuidInterface $categoryId,
    ) {
    }
}
