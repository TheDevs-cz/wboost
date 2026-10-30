<?php

declare(strict_types=1);

namespace WBoost\Web\Api\TemplateDocuments;

final class SetTemplateDocumentFieldsRequest
{
    /** @var list<mixed> `[{id?, name, kind, bindings: [{pageId, inputId}]}]` */
    public array $fields = [];
}
