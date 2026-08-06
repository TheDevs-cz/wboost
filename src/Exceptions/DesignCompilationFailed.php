<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

use WBoost\Web\Mcp\Design\CompileViolation;

/**
 * A design document the compiler refuses because it refers to something the
 * PROJECT does not have — an unknown font face, a gallery id that resolves to
 * nothing.
 *
 * The sibling of {@see InvalidDesignDocument}, same shape and same audience: the
 * message is written for an authoring agent, `preview_design` / `set_design`
 * (S5-T2/T3) hand it back as an MCP tool error, and it carries **every**
 * problem at once because five problems in one response are fixed in one turn.
 *
 * The two exceptions stay distinct because the fixes are: a parse failure is
 * answered by re-reading the grammar, a compile failure by calling
 * `get_context` (fonts) or `list_gallery` (assets). Folding them together would
 * hand the model one error class it has to introspect to know which tool to
 * call next.
 *
 * No `#[WithHttpStatus]`: MCP tool errors travel as JSON-RPC results at HTTP
 * 200, so a status code would be a promise nothing keeps.
 */
final class DesignCompilationFailed extends \Exception
{
    /**
     * Mirrors {@see InvalidDesignDocument::MAX_REPORTED} — a systematic mistake
     * in a large document must not bill the agent for a wall of identical
     * sentences.
     */
    public const int MAX_REPORTED = 20;

    /**
     * @param list<CompileViolation> $violations
     */
    private function __construct(
        public readonly array $violations,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param list<CompileViolation> $violations non-empty, in document order
     */
    public static function fromViolations(array $violations): self
    {
        if ($violations === []) {
            throw new \LogicException('DesignCompilationFailed requires at least one violation.');
        }

        return new self($violations, self::buildMessage($violations));
    }

    /**
     * @return list<array{path: string, code: string, message: string, allowed: list<string>}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (CompileViolation $violation): array => $violation->toArray(),
            $this->violations,
        );
    }

    /**
     * @param non-empty-list<CompileViolation> $violations
     */
    private static function buildMessage(array $violations): string
    {
        if (count($violations) === 1) {
            return 'The design cannot be compiled for this project. ' . $violations[0]->message;
        }

        $lines = [sprintf('The design cannot be compiled for this project (%d problems):', count($violations))];

        foreach (array_slice($violations, 0, self::MAX_REPORTED) as $index => $violation) {
            $lines[] = sprintf('%d. %s', $index + 1, $violation->message);
        }

        $hidden = count($violations) - self::MAX_REPORTED;

        if ($hidden > 0) {
            $lines[] = sprintf('… and %d more problem(s).', $hidden);
        }

        return implode("\n", $lines);
    }
}
