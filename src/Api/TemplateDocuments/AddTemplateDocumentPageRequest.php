<?php

declare(strict_types=1);

namespace WBoost\Web\Api\TemplateDocuments;

final class AddTemplateDocumentPageRequest
{
    public string $name = '';

    /** 0-based insert position; null = last. */
    public null|int $position = null;
}
