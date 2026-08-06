<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Lint;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Mcp\Design\CompilationContext;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Value\RichText;

/**
 * Everything {@see DesignLinter} needs to know about the project, gathered up
 * front — the same shape, and for the same reason, as
 * {@see CompilationContext}: the lint itself is then a pure function and every
 * check is assertable without a kernel.
 *
 * It **carries** the compilation context rather than restating parts of it. The
 * font check must be the compiler's font check (see {@see LintCode}'s class
 * note on why the linter reports it at all), and the image geometry the bounds
 * check measures must be the geometry the compiler emits; holding the very same
 * object is what makes both true by construction instead of by review.
 *
 * `#[Exclude]`: a value, never a service.
 */
#[Exclude]
readonly final class LintContext
{
    public function __construct(
        /** Whose fonts these are — {@see \WBoost\Web\Mcp\Design\Measure\TextMeasurer} loads faces per project. */
        public UuidInterface $projectId,
        /** The context the compiler will be handed: allowed faces + referenced gallery assets. */
        public CompilationContext $compilation,
        /**
         * The brand palette, lowercase `#rrggbb`, primary → secondary →
         * untyped.
         *
         * **Empty means "this project has no brand colours"**, not "no colour is
         * allowed": {@see DesignLinter} then skips the palette check entirely
         * rather than flagging every colour in the document.
         *
         * @var list<string>
         */
        public array $brandColors,
    ) {
    }

    /**
     * The palette is {@see ResolveRichTextOptions::computeColors()} — the same
     * pure static the fill toolbar, the API's `richTextOptions` and
     * `get_context` already answer with. Copying its ordering/dedup rules here
     * would give the agent one palette to design against and a different one to
     * fill with.
     *
     * @param array<Manual> $manuals `GetManuals::allForProject()`
     */
    public static function forProject(
        UuidInterface $projectId,
        CompilationContext $compilation,
        array $manuals,
    ): self {
        return new self($projectId, $compilation, ResolveRichTextOptions::computeColors($manuals));
    }

    public function hasBrandColors(): bool
    {
        return $this->brandColors !== [];
    }

    /**
     * Is `$color` a brand colour? Compared through
     * {@see RichText::normalizeHexColor()} — the app's one hex normalizer — so
     * `#FFF`, `#ffffff` and `#FFFFFF` are one colour here exactly as they are
     * at fill time.
     */
    public function isBrandColor(string $color): bool
    {
        $normalized = RichText::normalizeHexColor($color);

        return $normalized !== null && in_array($normalized, $this->brandColors, true);
    }
}
