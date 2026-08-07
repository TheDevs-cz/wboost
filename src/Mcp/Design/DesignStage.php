<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * WHICH gate raised a {@see DesignIssue} — and therefore what kind of fix it
 * takes.
 *
 * The design pipeline says no in five different places for five different
 * reasons, and an agent that cannot tell them apart wastes a turn guessing. The
 * severity says whether to stop; the stage says where to look:
 *
 * | stage | the question it answers | how the agent fixes it |
 * |---|---|---|
 * | `parse` | is this a well-formed DSL document? | re-read the grammar |
 * | `variant` | was it written for THIS variant? | change `canvas`, or preview against another variant |
 * | `lint` | is it a good design? | judgement — usually advisory |
 * | `compile` | does this project HAVE what it names? | call `get_context` / `list_gallery` |
 * | `overwrite` | what does WRITING this destroy? | fix the design it replaces, or acknowledge the loss |
 *
 * Two stages produce nothing but errors (`parse`, `compile`: the document is
 * unusable), one produces nothing but warnings (`variant`), and `lint` and
 * `overwrite` are the ones that produce both — see
 * {@see \WBoost\Web\Mcp\Design\Lint\LintCode} for why `font_not_allowed` is the
 * single lint ERROR, and {@see DesignOverwriteGuard} for what turns an
 * `overwrite` finding from an error into a warning.
 *
 * `#[Exclude]`: a value, never a service.
 */
#[Exclude]
enum DesignStage: string
{
    /** {@see Dsl\DslParser} — the document does not match the grammar. */
    case Parse = 'parse';

    /**
     * The document is fine, but it does not fit the variant it is being
     * previewed against. Nothing here ever blocks: the picture is what shows
     * the mismatch best.
     */
    case Variant = 'variant';

    /** {@see Lint\DesignLinter} — deterministic design review. */
    case Lint = 'lint';

    /** {@see DesignCompiler} — the project has no such font / picture. */
    case Compile = 'compile';

    /**
     * {@see DesignOverwriteGuard} — the ONLY stage that is not about the
     * submitted document at all. It reports what the variant's CURRENT design
     * holds that the DSL cannot express, and therefore what writing any
     * document over it would destroy.
     *
     * It exists only on the writing path: `preview_design` changes nothing, so
     * it has nothing to warn about. Findings are errors by default and warnings
     * once the caller has acknowledged them.
     */
    case Overwrite = 'overwrite';
}
