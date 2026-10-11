<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Template;

use Ramsey\Uuid\UuidInterface;

/**
 * Store the order the fill pages' "Všechna pole" panel lists a template's
 * fields in (inputIds, text and image mixed). Shared per template — and per
 * group, which is 1:1 with its template.
 */
readonly final class ArrangeTemplateFillFields
{
    /**
     * @param list<string> $inputIds
     */
    public function __construct(
        public UuidInterface $templateId,
        public array $inputIds,
    ) {
    }
}
