<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Lint;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Everything {@see DesignLinter} can say, and how much each of them costs.
 *
 * The enum is the contract: `DesignLinterTest` iterates {@see cases()} and
 * refuses to pass unless every single one has a fixture that triggers it
 * exactly once. Adding a case without a fixture is a failing test, which is
 * what keeps this list honest as it grows.
 *
 * ## Why `font_not_allowed` is here at all, when the compiler already throws it
 *
 * {@see \WBoost\Web\Mcp\Design\DesignCompiler} raises an unknown face as a hard
 * `DesignCompilationFailed` (plan §4.2 invariant 10). Duplicating a check is
 * normally how two implementations drift — so this one does not duplicate it:
 * the linter calls the compiler's own predicate
 * ({@see \WBoost\Web\Mcp\Design\CompilationContext::allowsFont()}) and the
 * compiler's own message builder
 * ({@see \WBoost\Web\Mcp\Design\DesignCompiler::fontNotAllowed()}), so the two
 * cannot disagree by construction, and a test asserts the linter's error text
 * is byte-identical to the violation the compiler throws.
 *
 * What it buys: `preview_design` lints BEFORE it compiles, so an agent that
 * wrote a bad font AND put a headline off the canvas learns both in one
 * response instead of fixing the font, spending another round trip and only
 * then hearing about the headline. Plan §0.5 is one deterministic pass that
 * tells the agent everything before a render is spent — a font error that only
 * ever surfaces as an exception thrown midway through compiling would abort
 * that pass and take the other findings with it.
 *
 * It stays an ERROR here because it is one downstream: the render would draw
 * the text in whatever face Chromium substitutes, which is a silently wrong
 * picture rather than a missing one. S5-T2 must not render on it.
 *
 * `#[Exclude]`: a value, never a service.
 */
#[Exclude]
enum LintCode: string
{
    /**
     * A text element names a face string this project does not have.
     *
     * The one ERROR. See the class note for why it is checked here as well as
     * in the compiler, and how the two are kept from disagreeing.
     */
    case FontNotAllowed = 'font_not_allowed';

    /**
     * An element's resolved rect leaves the canvas. Nothing is clamped anywhere
     * in the pipeline (`GridResolver` says so explicitly), so whatever hangs
     * over the edge is simply cropped out of the export.
     */
    case OutOfCanvasBounds = 'out_of_canvas_bounds';

    /**
     * Two text elements that are NOT in the same container tree intersect.
     * Inside a container an overlap is a legitimate design (the flow engine
     * preserves negative designed gaps on purpose); outside one it is two
     * paragraphs printing on top of each other.
     */
    case TextOverlap = 'text_overlap';

    /**
     * A text colour that is in none of the project's brand manuals. A
     * suggestion, never a rule — the export API itself accepts any hex.
     */
    case ColorNotInPalette = 'color_not_in_palette';

    /** A font size below the legibility floor for this canvas ({@see DesignLinter::legibilityFloor()}). */
    case FontSizeTooSmall = 'font_size_too_small';

    /**
     * A top-level container whose content is estimated not to fit its
     * `maxHeight` (or to run off the page past its `spaceAfter`). An ESTIMATE
     * from {@see \WBoost\Web\Mcp\Design\Measure\TextMeasurer} — the render is
     * the arbiter, and a strict API export answers 400 `container_overflow`.
     */
    case ContainerOverflowPredicted = 'container_overflow_predicted';

    /**
     * A container definition that groups fewer than two items (members +
     * nested children), which reflows nothing and is dropped by the canvas
     * sanitizer.
     *
     * ⚠️ **Unreachable from a parsed document** — `DslParser` refuses it as a
     * structural parse error, precisely because it would vanish between what
     * the agent wrote and what got saved. It is kept, rather than dropped from
     * the task list, because {@see DesignLinter} lints any
     * {@see \WBoost\Web\Mcp\Design\Dsl\DesignDocument}, and the decompiler
     * (S4-T5) builds documents WITHOUT the parser out of canvases authored in
     * the editor. Those can carry a definition whose second member was deleted
     * years ago — and telling the agent *"this container is inert and will
     * disappear when you save"* is exactly the feedback that stops a
     * `get_design` → `set_design` round trip from silently dropping it.
     */
    case ContainerTooFewItems = 'container_too_few_items';

    /**
     * An image element with neither a stand-in picture nor a fillable
     * placeholder input: it draws nothing and it fills nothing.
     */
    case ImageWithoutAssetOrPlaceholder = 'image_without_asset_or_placeholder';

    /**
     * `input.maxLength` is shorter than the element's own stand-in text
     * (plan risk R9). What the designer sees is then not what any consumer can
     * reproduce.
     */
    case MaxLengthBelowStandIn = 'max_length_below_stand_in';

    public function severity(): LintSeverity
    {
        return match ($this) {
            self::FontNotAllowed => LintSeverity::Error,
            default => LintSeverity::Warning,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
