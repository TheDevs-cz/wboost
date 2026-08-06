<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Lint;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Everything one {@see DesignLinter} pass found, in document order.
 *
 * The one method callers actually branch on is {@see hasErrors()}: plan §0.5
 * wants a font error to block **before** a render is attempted, so S5-T2 checks
 * it first and returns {@see toArray()} with no picture, and only otherwise
 * spends the Gotenberg round trip and ships the warnings alongside it.
 *
 * An empty report is the normal outcome. A linter that always has something to
 * say is one nobody reads, so `DesignLinterTest` pins a clean design at exactly
 * zero findings.
 *
 * `#[Exclude]`: a value, never a service.
 */
#[Exclude]
readonly final class LintReport
{
    public function __construct(
        /** @var list<LintFinding> in document order */
        public array $findings,
    ) {
    }

    public static function clean(): self
    {
        return new self([]);
    }

    public function isClean(): bool
    {
        return $this->findings === [];
    }

    public function hasErrors(): bool
    {
        return $this->errors() !== [];
    }

    /**
     * @return list<LintFinding>
     */
    public function errors(): array
    {
        return $this->withSeverity(LintSeverity::Error);
    }

    /**
     * @return list<LintFinding>
     */
    public function warnings(): array
    {
        return $this->withSeverity(LintSeverity::Warning);
    }

    /**
     * @return list<LintFinding>
     */
    public function withSeverity(LintSeverity $severity): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (LintFinding $finding): bool => $finding->severity() === $severity,
        ));
    }

    /**
     * @return list<LintFinding>
     */
    public function withCode(LintCode $code): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (LintFinding $finding): bool => $finding->code === $code,
        ));
    }

    /**
     * @return list<array{code: string, severity: string, slug: string, path: string, message: string}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (LintFinding $finding): array => $finding->toArray(),
            $this->findings,
        );
    }
}
