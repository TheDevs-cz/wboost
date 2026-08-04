<?php

declare(strict_types=1);

namespace WBoost\Web\Twig\Components\Template;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Services\Security\TemplateVariantVoter;
use WBoost\Web\Twig\Components\AbstractVariantFiller;

/**
 * Live Component that powers the user-fill / export page for a Custom Template
 * Variant. All behaviour lives in {@see AbstractVariantFiller} (shared with
 * the social-network module); this class binds the custom-template entity, voter and
 * routes.
 */
#[AsLiveComponent('Template:VariantFiller', template: 'components/VariantFiller.html.twig')]
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
    public null|TemplateVariant $variant = null;

    protected function nullableVariant(): null|TemplateVariant
    {
        return $this->variant;
    }

    protected function viewAttribute(): string
    {
        return TemplateVariantVoter::VIEW;
    }

    public function downloadPath(): string
    {
        return $this->generateUrl('template_variant_download', [
            'variantId' => $this->variantEntity()->id,
        ]);
    }

    public function uploadPath(string $inputId): string
    {
        return $this->generateUrl('template_variant_placeholder_upload', [
            'variantId' => $this->variantEntity()->id,
            'inputId' => $inputId,
        ]);
    }
}
