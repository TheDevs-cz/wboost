<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Lint;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * How much a {@see LintFinding} costs.
 *
 * The split exists so `preview_design` (S5-T2) can act on it without reading
 * messages: **errors block the render, warnings ride along with the picture.**
 * That is the whole contract, and it is why severity is modelled explicitly
 * rather than inferred from a code prefix.
 *
 * Severity is a property of the CODE ({@see LintCode::severity()}), never of the
 * occurrence — the same problem cannot be fatal in one design and cosmetic in
 * the next, and a linter that decided case by case would be unpredictable to
 * the agent it is advising.
 *
 * `#[Exclude]`: a value, never a service.
 */
#[Exclude]
enum LintSeverity: string
{
    /**
     * The design cannot be rendered at all. There is exactly one today
     * ({@see LintCode::FontNotAllowed}) and it is the compiler's hard refusal
     * seen one stage earlier — see {@see LintCode}'s class note.
     */
    case Error = 'error';

    /**
     * The design renders, but probably not the way it was meant to. Never
     * blocks: every warning here is a judgement or an estimate, and a linter
     * that refuses work on a judgement gets routed around.
     */
    case Warning = 'warning';
}
