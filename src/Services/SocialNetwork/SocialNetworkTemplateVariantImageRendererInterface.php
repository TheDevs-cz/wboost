<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Value\ResolvedInputOverrides;

interface SocialNetworkTemplateVariantImageRendererInterface
{
    /**
     * Renders a variant to a PNG response. Indexes in $overrides correspond to
     * positions in `$variant->inputs`, which match
     * `canvas.getObjects('textbox')[i]` on the Fabric.js side.
     */
    public function render(SocialNetworkTemplateVariant $variant, ResolvedInputOverrides $overrides): Response;
}
