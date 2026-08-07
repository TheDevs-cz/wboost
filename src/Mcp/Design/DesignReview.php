<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The outcome of one {@see DesignPreflight} pass: everything worth saying about
 * a design, and — when nothing blocks — the {@see CompiledDesign} that may now
 * be drawn or written.
 *
 * The two halves are deliberately ONE object rather than a compiled design plus
 * a separate warning list. `preview_design` and `set_design` both have to answer
 * the same two questions in the same order ("may I proceed?", "what do I tell
 * the agent either way?"), and a shape where {@see $compiled} is null EXACTLY
 * when a blocking issue exists is what stops a caller from rendering a design
 * the review rejected — there is simply nothing to render with.
 *
 * `#[Exclude]`: a value, never a service.
 */
#[Exclude]
readonly final class DesignReview
{
    /**
     * @param list<DesignIssue> $issues in pipeline order: parse, then the
     *        variant fit, then lint in document order, then compile
     * @param null|CompiledDesign $compiled null exactly when {@see $issues}
     *        holds at least one error
     */
    private function __construct(
        public array $issues,
        public null|CompiledDesign $compiled,
    ) {
    }

    /**
     * The design cannot go on. `$issues` carries the error(s) that stopped it
     * AND every advisory finding gathered before it — see
     * {@see DesignIssue::fromLintReport()} for why the warnings travel too.
     *
     * @param list<DesignIssue> $issues
     */
    public static function blocked(array $issues): self
    {
        return new self($issues, null);
    }

    /**
     * @param list<DesignIssue> $issues warnings only, by construction
     */
    public static function accepted(CompiledDesign $compiled, array $issues): self
    {
        return new self($issues, $compiled);
    }

    public function isBlocked(): bool
    {
        return $this->compiled === null;
    }

    /**
     * @return list<DesignIssue>
     */
    public function errors(): array
    {
        return array_values(array_filter($this->issues, static fn (DesignIssue $issue): bool => $issue->isBlocking()));
    }

    /**
     * @return list<DesignIssue>
     */
    public function warnings(): array
    {
        return array_values(array_filter($this->issues, static fn (DesignIssue $issue): bool => !$issue->isBlocking()));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn (DesignIssue $issue): array => $issue->toArray(), $this->issues);
    }
}
