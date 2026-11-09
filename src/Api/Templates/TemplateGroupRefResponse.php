<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Templates;

/**
 * The group a template belongs to (`TemplateResponse::$group`).
 */
final readonly class TemplateGroupRefResponse
{
    /**
     * @param list<TemplateGroupRepeatableRowResponse> $repeatableRows the
     *   group fill's repeatable rows (one shared count each); the members'
     *   `variants[].repeatableRows[]` describe their members and slot ids
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $repeatableRows = [],
    ) {
    }
}
