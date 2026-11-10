<?php

declare(strict_types=1);

namespace WBoost\Web\Api\TemplateDocuments;

final class CreateTemplateDocumentRequest
{
    public string $name = '';

    public null|string $categoryId = null;

    /** @var array<string, mixed> `{unit, unitWidth, unitHeight, preset?, bleedMm?, cropMarks?}` */
    public array $dimension = [];

    /** @var list<string> */
    public array $templateIds = [];
}
