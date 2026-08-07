<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design;

use Mcp\Exception\ToolCallException;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * The variant this account may WRITE a design to — {@see editable()} plus
     * the one thing only a writer cares about.
     *
     * A group-created variant is refused here and nowhere else (plan §4.5-22,
     * mirroring `TemplateVariantEditorController`'s redirect to the group
     * editor): every variant of a template group shares one design, so a
     * single-variant save would be silently clobbered by the next group save.
     * Reading one, linting one or drawing one changes nothing, which is why
     * `preview_design` and `get_design` take {@see editable()} instead and
     * accept them.
     *
     * Note the wording names the group and links its editor. There is no MCP
     * tool for group design yet, and "this is not supported" with no next step
     * is the refusal that leaves an agent guessing — the browser is the answer
     * today, and `find_templates` / `describe_variant` already publish the
     * `grouped` flag that predicts this refusal before it happens.
     */
    public function writable(string $variantId): TemplateVariant
    {
        $variant = $this->editable($variantId);

        if ($variant->group !== null) {
            throw new ToolCallException(sprintf(
                'Template variant %s belongs to the synchronized template group "%s" and cannot be designed on its own. Every variant of a group shares ONE design across its dimensions, so writing this variant alone would be overwritten by the next group save. There is no MCP tool for group design yet: open the group editor at %s to change it. describe_variant reports grouped: true for exactly these variants — and a grouped template can still hold hand-added variants, which carry grouped: false and are writable here.',
                $variantId,
                $variant->group->name,
                $this->urlGenerator->generate(
                    'template_group_editor',
                    ['groupId' => $variant->group->id->toString()],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
            ));
        }

        return $variant;
    }

    /**
     * The variant this account may DESIGN on, or the one refusal that fits.
     *
     * Note what is deliberately absent: a `variant->group !== null` check —
     * that is {@see writable()}, and only the writing tool asks for it.
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
