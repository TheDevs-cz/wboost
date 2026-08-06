<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Lint;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One thing {@see DesignLinter} noticed, addressed BOTH ways an agent can act
 * on it.
 *
 * - {@see $slug} is the element's DSL id — the handle the agent itself chose
 *   and the only stable name across a `set_design` (element indexes move when
 *   an element is inserted, slugs do not). Every finding carries one.
 * - {@see $path} is the same JSON pointer vocabulary
 *   {@see \WBoost\Web\Mcp\Design\Dsl\DslViolation} and
 *   {@see \WBoost\Web\Mcp\Design\CompileViolation} use (`elements[2].size`), so
 *   an agent reads one addressing scheme across parse, compile and lint.
 *
 * {@see $message} is a standalone English sentence that already contains the
 * path and says what to DO about it — the same standard the parser's errors
 * hold themselves to. A warning that only names a problem costs a round trip
 * to guess the fix.
 *
 * `#[Exclude]`: a value, never a service.
 */
#[Exclude]
readonly final class LintFinding
{
    public function __construct(
        public LintCode $code,
        /** The offending element's DSL slug id. */
        public string $slug,
        /** Dotted/bracketed path into the document, e.g. `elements[2].color`. */
        public string $path,
        /** Human (English) sentence, containing the path, ending with a full stop. */
        public string $message,
    ) {
    }

    /**
     * Delegated, never stored: severity belongs to the code, so an occurrence
     * cannot carry one that disagrees with it.
     */
    public function severity(): LintSeverity
    {
        return $this->code->severity();
    }

    /**
     * @return array{code: string, severity: string, slug: string, path: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'severity' => $this->severity()->value,
            'slug' => $this->slug,
            'path' => $this->path,
            'message' => $this->message,
        ];
    }
}
