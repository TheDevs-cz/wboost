<?php

declare(strict_types=1);

namespace WBoost\Web\Twig\Components\Flyer;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use WBoost\Web\Entity\FlyerTemplateVariant;
use WBoost\Web\Services\Security\FlyerTemplateVariantVoter;
use WBoost\Web\Twig\Components\AbstractVariantFiller;

/**
 * Live Component that powers the user-fill / export page for a Flyer Template
 * Variant. All behaviour lives in {@see AbstractVariantFiller} (shared with
 * the social-network module); this class binds the flyer entity, voter and
 * routes.
 */
#[AsLiveComponent('Flyer:VariantFiller', template: 'components/VariantFiller.html.twig')]
final class VariantFiller extends AbstractVariantFiller
{
    /**
     * The variant being filled. Live Components hydrate Doctrine entities by
     * id, so this value flows through round-trips as the variant's UUID.
     *
     * Declared nullable to satisfy PHPStan's uninitialized-property check —
     * Live Components hydrate the property after construction, so a non-null
     * default is not possible at the language level. In practice it is always
     * set when the component renders.
     */
    #[LiveProp]
    public null|FlyerTemplateVariant $variant = null;

    protected function nullableVariant(): null|FlyerTemplateVariant
    {
        return $this->variant;
    }

    protected function viewAttribute(): string
    {
        return FlyerTemplateVariantVoter::VIEW;
    }

    public function downloadPath(): string
    {
        return $this->generateUrl('flyer_template_variant_download', [
            'variantId' => $this->variantEntity()->id,
        ]);
    }

    public function uploadPath(string $inputId): string
    {
        return $this->generateUrl('flyer_variant_placeholder_upload', [
            'variantId' => $this->variantEntity()->id,
            'inputId' => $inputId,
        ]);
    }
}
