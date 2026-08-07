<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design;

use Mcp\Exception\ToolCallException;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\TemplateVariantNotFound;
use WBoost\Web\Mcp\Fill\VariantFill;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Security\TemplateVariantVoter;

/**
 * Variant lookup for the DESIGN tools —
 * {@see TemplateVariantVoter::EDIT} instead of the `VIEW` the fill tools use
 * (plan §3.3: `preview_design`, `set_design` and `get_design` are all gated on
 * EDIT).
 *
 * Separate from {@see VariantFill::variant()} because the gate is genuinely
 * different, and reuses its refusal factories because the WORDING must not be:
 * a server that phrases "no such variant" two ways teaches an agent two
 * vocabularies for one dead end.
 *
 * ## Where the anti-enumeration rule stops
 *
 * `VariantFill::notFound()` deliberately makes "not yours" indistinguishable
 * from "does not exist", or any token becomes an id-probing oracle. That rule
 * is kept HERE for the VIEW gate — an id the caller cannot even see reports the
 * same words it would for a nonexistent row.
 *
 * It is deliberately NOT extended to the EDIT gate. A caller who may VIEW the
 * variant has already been told it exists (`find_templates` lists it,
 * `describe_variant` describes it, `render_variant` draws it), so answering
 * "not found" for a variant the same token just rendered would hide nothing and
 * would send the agent hunting for a wrong id. It gets the real reason instead,
 * which is the one that can be acted on: sharing grants viewing, designing
 * needs ownership.
 */
readonly final class DesignVariants
{
    public function __construct(
        private Security $security,
        private TemplateVariantRepository $templateVariantRepository,
    ) {
    }

    /**
     * The variant this account may DESIGN on, or the one refusal that fits.
     *
     * Note what is deliberately absent: a `variant->group !== null` check.
     * Group-created variants are refused by the WRITE (plan §4.5-22, mirroring
     * `TemplateVariantEditorController`'s redirect) because a single-variant
     * save would be clobbered by the next group save. Reading one, linting one
     * or drawing one changes nothing, so `preview_design` and `get_design`
     * accept them; S5-T3 adds the refusal at the boundary where it means
     * something.
     */
    public function editable(string $variantId): TemplateVariant
    {
        if (!Uuid::isValid($variantId)) {
            throw VariantFill::notAVariantId($variantId);
        }

        try {
            $variant = $this->templateVariantRepository->get(Uuid::fromString($variantId));
        } catch (TemplateVariantNotFound) {
            throw VariantFill::notFound($variantId);
        }

        if (!$this->security->isGranted(TemplateVariantVoter::VIEW, $variant)) {
            throw VariantFill::notFound($variantId);
        }

        if (!$this->security->isGranted(TemplateVariantVoter::EDIT, $variant)) {
            throw new ToolCallException(sprintf(
                'Template variant %s can be read by this account but not designed on. Changing a design requires owning the project (or an admin account); a project shared with you only grants viewing. Use render_variant to see it, or ask the project owner to make the change.',
                $variantId,
            ));
        }

        return $variant;
    }
}
