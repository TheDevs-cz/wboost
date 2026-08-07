<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use WBoost\Web\Exceptions\DesignCompilationFailed;
use WBoost\Web\Exceptions\InvalidDesignDocument;
use WBoost\Web\Mcp\Design\Dsl\CanvasSpec;
use WBoost\Web\Mcp\Design\Dsl\DslViolation;
use WBoost\Web\Mcp\Design\Lint\LintFinding;
use WBoost\Web\Mcp\Design\Lint\LintReport;
use WBoost\Web\Mcp\Design\Lint\LintSeverity;

/**
 * ONE thing wrong with a design, whichever gate noticed it.
 *
 * ## Why the three vocabularies are merged into one
 *
 * A design goes through three refusal-capable stages —
 * {@see Dsl\DslParser} ({@see DslViolation}), {@see Lint\DesignLinter}
 * ({@see LintFinding}) and {@see DesignCompiler} ({@see CompileViolation}) —
 * and each already models its own problems well. What none of them can do is
 * describe a single `preview_design` call, which routinely produces findings
 * from more than one of them at once (that is the entire reason S4-T6 taught
 * the linter to report `font_not_allowed` instead of letting the compiler abort
 * the pass). Handing an agent three differently-shaped lists in one reply would
 * make it learn three readers for one job.
 *
 * So they merge here, losslessly: every source already carries a **path** in
 * the same JSON-pointer vocabulary (`elements[2].font`) and a **standalone
 * English sentence** that says what to do, and this adds the two things the
 * merged list needs to stay readable — {@see $severity} (blocking or advisory)
 * and {@see $stage} (which gate, i.e. what kind of fix).
 *
 * ## Severity is `LintSeverity`, not a second enum
 *
 * A parse violation and a compile violation are ALWAYS errors — the document is
 * unusable — so they have no severity of their own to lose. Declaring a
 * `DesignIssueSeverity` with the same two cases would be a copy waiting to
 * drift from the one place severity is actually decided
 * ({@see \WBoost\Web\Mcp\Design\Lint\LintCode::severity()}), for the sake of a
 * name. The enum's own docblock already states the contract this shape rests
 * on: errors block the render, warnings ride along with the picture.
 *
 * `#[Exclude]`: a value, never a service.
 */
#[Exclude]
readonly final class DesignIssue
{
    public function __construct(
        public LintSeverity $severity,
        public DesignStage $stage,
        /**
         * The source stage's own machine code — {@see Dsl\DslErrorCode},
         * {@see Lint\LintCode} or {@see CompileErrorCode}. Kept as a string
         * because the three vocabularies are deliberately separate enums (a
         * merged one would have to be re-opened every time any stage grew a
         * case), and `stage` already disambiguates the one value they share.
         */
        public string $code,
        /**
         * The offending element's DSL slug, when the finding can be tied to
         * one. Null for document-level problems (`canvas.width`, a malformed
         * payload) and for parse violations — a document that failed to parse
         * has no elements to name.
         */
        public null|string $slug,
        /** Dotted/bracketed path into the document, e.g. `elements[2].font`. */
        public string $path,
        /** Standalone English sentence, already containing the path. */
        public string $message,
        /**
         * The values that WOULD have worked, when the set is knowable and small
         * enough to help — the export API's `allowedFonts` pattern. Empty
         * otherwise, and then omitted from {@see toArray()}.
         *
         * @var list<string>
         */
        public array $allowed = [],
    ) {
    }

    /**
     * Everything the parser refused, all of it — the parser reports every
     * problem in one pass precisely so this list can be acted on in one turn.
     *
     * @return list<self>
     */
    public static function fromParseFailure(InvalidDesignDocument $failure): array
    {
        return array_map(
            static fn (DslViolation $violation): self => new self(
                LintSeverity::Error,
                DesignStage::Parse,
                $violation->code->value,
                null,
                $violation->path,
                $violation->message,
            ),
            $failure->violations,
        );
    }

    /**
     * The whole lint report — warnings included, even when it also carries the
     * error that blocks. Dropping the warnings on a blocked pass would cost the
     * agent exactly the extra round trip S4-T6 exists to avoid.
     *
     * @return list<self>
     */
    public static function fromLintReport(LintReport $report): array
    {
        return array_map(
            static fn (LintFinding $finding): self => new self(
                $finding->severity(),
                DesignStage::Lint,
                $finding->code->value,
                $finding->slug,
                $finding->path,
                $finding->message,
            ),
            $report->findings,
        );
    }

    /**
     * @return list<self>
     */
    public static function fromCompileFailure(DesignCompilationFailed $failure): array
    {
        return array_map(
            static fn (CompileViolation $violation): self => new self(
                LintSeverity::Error,
                DesignStage::Compile,
                $violation->code->value,
                null,
                $violation->path,
                $violation->message,
                $violation->allowed,
            ),
            $failure->violations,
        );
    }

    /**
     * The design was authored for a canvas of a different size than the variant
     * it is being previewed on.
     *
     * A WARNING, never a refusal. The render is issued at the VARIANT's real
     * dimensions (the renderer takes them from the row, not from the document),
     * so a mismatched design still produces a picture — one where every
     * `at`-placed element landed by the wrong grid and every absolute `x`/`y`
     * sits at the wrong fraction of the page. That is a thing an agent can see
     * and fix in the next call, which is exactly what a preview is for; and
     * blocking on it would refuse a design the writing path is happy to accept.
     */
    public static function canvasSizeMismatch(CanvasSpec $canvas, int $variantWidth, int $variantHeight): self
    {
        return new self(
            LintSeverity::Warning,
            DesignStage::Variant,
            'canvas_size_mismatch',
            null,
            'canvas',
            sprintf(
                'canvas is declared %d x %d but this variant is %d x %d canvas pixels. The render always uses the variant\'s size, so every "at" placement was resolved against the wrong grid and every absolute x/y landed at the wrong fraction of the page. Set canvas.width to %d and canvas.height to %d, or preview against a variant of the size you designed for.',
                $canvas->width,
                $canvas->height,
                $variantWidth,
                $variantHeight,
                $variantWidth,
                $variantHeight,
            ),
        );
    }

    /**
     * Something the variant's CURRENT design holds that the DSL cannot carry —
     * i.e. what writing a document over it would destroy.
     *
     * The loss keeps its own code, path and sentence: {@see DesignLoss} already
     * words each one as standalone English that says how to AVOID the loss
     * ("upload the picture to the gallery first and reference it by id"), which
     * is a better fix than accepting it. All this adds is the severity, and the
     * severity is the whole gate — see {@see DesignOverwriteGuard}.
     */
    public static function fromDesignLoss(DesignLoss $loss, LintSeverity $severity): self
    {
        return new self(
            $severity,
            DesignStage::Overwrite,
            $loss->code->value,
            null,
            $loss->path,
            $loss->message,
        );
    }

    public function isBlocking(): bool
    {
        return $this->severity === LintSeverity::Error;
    }

    /**
     * The wire shape. `slug` and `allowed` are omitted when they carry nothing
     * — an agent pays for every key of every issue, and `"slug": null` on a
     * document-level problem is a byte cost with no reader.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $issue = [
            'severity' => $this->severity->value,
            'stage' => $this->stage->value,
            'code' => $this->code,
            'path' => $this->path,
            'message' => $this->message,
        ];

        if ($this->slug !== null) {
            $issue['slug'] = $this->slug;
        }

        if ($this->allowed !== []) {
            $issue['allowed'] = $this->allowed;
        }

        return $issue;
    }
}
