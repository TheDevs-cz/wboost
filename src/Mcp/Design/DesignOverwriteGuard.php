<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design;

use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Mcp\Design\Lint\LintSeverity;

/**
 * The one gate that protects a design nobody asked to change.
 *
 * `set_design` REPLACES a variant's whole canvas. Every other stage of the
 * pipeline judges the incoming document; this one judges the design being
 * thrown away, and it is the reason the writing tool can be trusted with a
 * template a human spent an afternoon on.
 *
 * ## The hazard, in one paragraph
 *
 * DSL v1 is a lossy projection of a Fabric canvas — see {@see DesignDecompiler}
 * on why it had to be, and {@see DesignLossCode} for the inventory. Most losses
 * are things the DSL cannot *address*; the ones that matter are the ones it
 * cannot address AND would delete on the way back. The worst is not exotic: a
 * background uploaded through the add/edit-variant form is stored under
 * `custom-templates/{variantId}/background-*.png` with **no `file_upload` row
 * at all**, so the DSL — which names pictures by gallery id — cannot name it.
 * Decompiling reports {@see DesignLossCode::AssetUnresolved}; compiling any
 * document back over it produces a variant with **no background**. S4-T5 found
 * one in 1 of 5 sampled production canvases. Design-hidden layers, Rects,
 * Paths and Groups fail the same way (`object_dropped`), and per-character
 * styling, shadows and list configuration fail it more quietly.
 *
 * A warning would not be enough, because writing the document back is precisely
 * what this tool does: the agent would read the warning in the same reply that
 * told it the write had already succeeded.
 *
 * ## The rule
 *
 * > Decompile the variant's CURRENT canvas. Every
 * > {@see DecompiledDesign::destructiveLosses() destructive} loss is an ERROR
 * > that refuses the write — unless the caller passed `acknowledgeLosses`, in
 * > which case the very same findings come back as WARNINGS and the write
 * > proceeds.
 *
 * Three things about that rule are deliberate.
 *
 * **It keys on `DesignLoss::$destructive`, not on a hand-picked set of codes.**
 * That flag already means exactly *"writing the decompiled document back would
 * DESTROY the thing described"*, decided at the site that knows. Refusing only
 * for `asset_unresolved` — the loss the plan called out — would be arbitrary:
 * a logo drawn as a Path and a design-hidden alternate headline disappear just
 * as completely, and an agent whose mental model is "it protects backgrounds"
 * would be wrong in the way that costs a designer real work. One rule is also
 * one thing to learn.
 *
 * **The escape hatch is a blanket boolean, and the refusal enumerates what it
 * covers.** An agent legitimately REPLACING a design must not be blocked
 * forever by a background it never intended to keep, so there has to be a way
 * through; the question is only whether it should be blanket or itemized.
 * Itemizing (naming each loss back) would be brittle — the paths shift as soon
 * as the design does — and would add no safety, because the list being echoed
 * is the list just received. What makes the acknowledgement INFORMED is the
 * shape of the interaction instead: the flag has no reason to exist until a
 * refusal has already listed every loss, and once it is set the same losses are
 * reported again as warnings, so the transcript records what was destroyed.
 *
 * **It is idempotent in the useful direction.** A canvas this tool wrote
 * decompiles losslessly, so the guard fires at most once per variant: exactly
 * at the boundary between a browser-authored design and a DSL-authored one, and
 * never again during the agent's own iteration.
 *
 * ## What it deliberately does NOT do
 *
 * It does not compare the incoming document against the current one. "Did the
 * agent keep the background element?" is unanswerable in the failing case —
 * there is no id for it to have kept — and a diff would refuse honest
 * wholesale rewrites while passing a document that happened to mention the
 * right slugs. The question that can be answered exactly is the one asked:
 * *can this design be expressed at all?*
 *
 * Non-destructive losses ({@see DesignLoss::$destructive} false — today only
 * the canvas-level background of a legacy {@see \WBoost\Web\Value\BackgroundMode::Canvas}
 * variant, which lives on the row and survives any canvas write) are reported
 * as warnings whether acknowledged or not. They never block, because nothing is
 * lost; they are said out loud because "my design has no background element and
 * the render has a background" is otherwise a mystery.
 */
readonly final class DesignOverwriteGuard
{
    public function __construct(
        private DesignDecompiler $decompiler,
        private DecompilationContextFactory $contexts,
    ) {
    }

    /**
     * What writing over this variant costs, as issues in the pipeline's own
     * vocabulary.
     *
     * An empty list means the current design is fully expressible and the write
     * is free. A list with at least one blocking issue means the write must not
     * happen; {@see blocks()} is the predicate for that.
     *
     * Nothing here is caught. {@see DesignDecompiler} is written to answer for
     * any canvas rather than to throw, so an exception escaping it is a bug —
     * and the right failure for a bug in the thing that guards against data
     * loss is the closed one: the tool never reaches its write.
     *
     * @param bool $acknowledged the caller's `acknowledgeLosses` flag
     * @return list<DesignIssue>
     */
    public function review(TemplateVariant $variant, bool $acknowledged): array
    {
        $decompiled = $this->decompiler->forVariant($variant, $this->contexts->forVariant($variant));

        /** @var list<DesignIssue> $issues */
        $issues = [];

        foreach ($decompiled->losses as $loss) {
            $blocking = $loss->destructive && !$acknowledged;

            $issues[] = DesignIssue::fromDesignLoss(
                $loss,
                $blocking ? LintSeverity::Error : LintSeverity::Warning,
            );
        }

        return $issues;
    }

    /**
     * @param list<DesignIssue> $issues as returned by {@see review()}
     */
    public static function blocks(array $issues): bool
    {
        foreach ($issues as $issue) {
            if ($issue->isBlocking()) {
                return true;
            }
        }

        return false;
    }
}
