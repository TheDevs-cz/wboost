<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Templates;

/**
 * The group a template belongs to (`TemplateResponse::$group`).
 */
final readonly class TemplateGroupRefResponse
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
