<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Template;

use Ramsey\Uuid\UuidInterface;

/**
 * Upgrade a legacy canvas-mode variant to layer mode, keeping its rendered
 * look (see {@see \WBoost\Web\Services\Editor\LegacyBackgroundToLayer}).
 * A no-op for a variant that is already layer-mode or has no background.
 *
 * `backgroundInputId` is passed in rather than generated because variants of
 * one template GROUP must share their background layer's inputId — it is the
 * join key group sync uses to fan a "Pozadí" pick out to the siblings.
 */
readonly final class ConvertTemplateVariantBackgroundToLayer
{
    public function __construct(
        public UuidInterface $variantId,
        public string $backgroundInputId,
    ) {
    }
}
