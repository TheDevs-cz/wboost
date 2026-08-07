<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design;

use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\DesignCompilationFailed;
use WBoost\Web\Exceptions\InvalidDesignDocument;
use WBoost\Web\Mcp\Design\Dsl\DesignDocument;
use WBoost\Web\Mcp\Design\Dsl\DslParser;
use WBoost\Web\Mcp\Design\Lint\DesignLinter;
use WBoost\Web\Mcp\Design\Lint\LintContext;
use WBoost\Web\Query\GetManuals;

/**
 * Everything that happens to a design document BEFORE anyone spends a render on
 * it: **parse → variant fit → lint → compile**, gathered into one
 * {@see DesignReview}.
 *
 * `preview_design` (S5-T2) and `set_design` (S5-T3) run exactly this and then
 * diverge only in what they do with the result — draw it, or write it. Sharing
 * the pass is what guarantees the plan's core promise for the design loop: a
 * document that previewed cleanly cannot be refused by the write for a reason
 * the preview did not surface.
 *
 * ## The order is the contract
 *
 * Each stage exists because the one before it left a question it could not
 * answer, and running them out of order costs the agent turns:
 *
 * 1. **Parse first.** A malformed document has no elements to lint and no fonts
 *    to check. {@see DslParser} reports every violation at once, so a broken
 *    document is answered completely in one reply.
 * 2. **The variant fit next**, because it is the cheapest thing that can make
 *    every later finding misleading: a document authored for 1080 × 1080 and
 *    previewed on A4 will also trip half the bounds warnings, and the agent
 *    should read the canvas mismatch first.
 * 3. **Lint before compile — deliberately.** The compiler treats an unknown
 *    font face as a hard refusal and aborts the whole pass on the first one, so
 *    compiling first would hide the off-canvas headline behind the bad font
 *    string. S4-T6 gave {@see DesignLinter} the compiler's OWN predicate and
 *    message builder for exactly this: `font_not_allowed` is reported here, as
 *    an ERROR, alongside everything else the pass found, and the compile never
 *    runs. Same wording either way, one round trip instead of two.
 * 4. **Compile last**, for what the linter cannot see — a gallery id that
 *    resolves to nothing, a fillable background with no stand-in picture. Its
 *    violations are translated exactly like the rest.
 *
 * Consequence, and the property S5-T2's test pins: **an error means no
 * `CompiledDesign` exists**, so a caller cannot render a design this pass
 * rejected even by mistake.
 *
 * ## Identity comes from the variant, never from the document
 *
 * {@see DesignIdentity::fromInputs()} is built off the variant's persisted
 * inputs, so a slug that already names an input keeps that input's `inputId`
 * (plan §4.1). It matters here and not only in `set_design`: a preview whose
 * ids differ from the ids the write would produce is a preview of a different
 * variant, and container membership — which addresses members BY `inputId` —
 * would reflow differently in the two.
 */
readonly final class DesignPreflight
{
    public function __construct(
        private CompilationContextFactory $compilationContextFactory,
        private DesignLinter $linter,
        private DesignCompiler $compiler,
        private GetManuals $getManuals,
    ) {
    }

    /**
     * @param mixed $design the decoded design document, exactly as the tool
     *        received it — {@see DslParser::parse()} is the validating entry
     *        point and takes it untrusted on purpose
     */
    public function review(TemplateVariant $variant, mixed $design): DesignReview
    {
        try {
            $document = DslParser::parse($design);
        } catch (InvalidDesignDocument $invalid) {
            return DesignReview::blocked(DesignIssue::fromParseFailure($invalid));
        }

        /** @var list<DesignIssue> $issues */
        $issues = self::fitsTheVariant($document, $variant);

        $project = $variant->template->project;
        $compilation = $this->compilationContextFactory->forProject($project, $document);

        $report = $this->linter->lint($document, LintContext::forProject(
            $project->id,
            $compilation,
            $this->getManuals->allForProject($project->id),
        ));

        foreach (DesignIssue::fromLintReport($report) as $issue) {
            $issues[] = $issue;
        }

        if ($report->hasErrors()) {
            // No compile, and therefore no candidate to render. See the class
            // docblock: this is the branch that keeps a font error from ever
            // reaching Gotenberg.
            return DesignReview::blocked($issues);
        }

        try {
            $compiled = $this->compiler->compile(
                $document,
                $compilation,
                DesignIdentity::fromInputs($variant->inputs, $variant->imageInputs),
            );
        } catch (DesignCompilationFailed $failed) {
            foreach (DesignIssue::fromCompileFailure($failed) as $issue) {
                $issues[] = $issue;
            }

            return DesignReview::blocked($issues);
        }

        return DesignReview::accepted($compiled, $issues);
    }

    /**
     * @return list<DesignIssue>
     */
    private static function fitsTheVariant(DesignDocument $document, TemplateVariant $variant): array
    {
        $width = $variant->dimension->width();
        $height = $variant->dimension->height();

        if ($document->canvas->width === $width && $document->canvas->height === $height) {
            return [];
        }

        return [DesignIssue::canvasSizeMismatch($document->canvas, $width, $height)];
    }
}
