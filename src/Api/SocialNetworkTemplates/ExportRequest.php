<?php

declare(strict_types=1);

namespace WBoost\Web\Api\SocialNetworkTemplates;

final class ExportRequest
{
    /**
     * Map of inputId UUID → value (string or `{ value, hide }` object).
     * Inputs whose ids are missing keep the variant's default canvas text.
     * Locked inputs cannot be addressed and are always served from the canvas
     * defaults. Discover ids via `GET /api/social-network-templates`
     * (`variants[].inputs[].id`). Unknown ids are silently ignored.
     *
     * @var array<string, mixed>
     */
    public array $inputs = [];
}
