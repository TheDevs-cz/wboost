<?php

declare(strict_types=1);

namespace WBoost\Web\Api\TemplateDocuments;

final class UpdateTemplateDocumentRequest
{
    public null|string $name = null;

    public null|string $categoryId = null;

    /** Print documents only: crop marks in the print PDF. */
    public null|bool $cropMarks = null;
}
