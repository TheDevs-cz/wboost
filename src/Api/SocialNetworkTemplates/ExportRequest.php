<?php

declare(strict_types=1);

namespace WBoost\Web\Api\SocialNetworkTemplates;

final class ExportRequest
{
    /**
     * Map of input name → value. Inputs whose names are missing keep the
     * variant's default canvas text. Locked or unnamed inputs cannot be
     * addressed and are always served from the canvas defaults.
     *
     * @var array<string, string>
     */
    public array $inputs = [];
}
