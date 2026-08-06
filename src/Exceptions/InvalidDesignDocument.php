<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

use WBoost\Web\Mcp\Design\Dsl\DslErrorCode;
use WBoost\Web\Mcp\Design\Dsl\DslViolation;

/**
 * A design document the strict DSL parser refuses.
 *
 * The message is written for an **authoring agent**, not for a log: it is what
 * `preview_design` / `set_design` (S5-T2/T3) hand back as an MCP tool error and
 * what the model self-corrects from. Hence the structured
 * {@see $violations} — path, code and sentence per problem — and hence the
 * report carrying **all** of them at once rather than dying on the first: five
 * problems in one response are fixed in one turn, five responses take five.
 *
 * No `#[WithHttpStatus]`: MCP tool errors travel as JSON-RPC results at HTTP
 * 200 (`ToolCallException`), so a status code would be a promise nothing keeps
 * — the same reasoning as {@see InvalidMcpScopes}.
 */
final class InvalidDesignDocument extends \Exception
{
    /**
     * Only this many problems are spelled out in the message; the rest are
     * counted. A 200-element document with a systematic mistake would
     * otherwise return a wall of text an agent has to pay for token by token,
     * and the first twenty already show the pattern. {@see $violations} keeps
     * every one of them for programmatic callers.
     */
    public const int MAX_REPORTED = 20;

    /**
     * @param list<DslViolation> $violations
     */
    private function __construct(
        public readonly array $violations,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param list<DslViolation> $violations non-empty, in document order
     */
    public static function fromViolations(array $violations): self
    {
        if ($violations === []) {
            throw new \LogicException('InvalidDesignDocument requires at least one violation.');
        }

        return new self($violations, self::buildMessage($violations));
    }

    /**
     * The payload never even reached the grammar — not JSON, or not an object.
     */
    public static function malformed(string $reason): self
    {
        $violation = new DslViolation('', DslErrorCode::MalformedDocument, $reason);

        return new self([$violation], $reason);
    }

    /**
     * @return list<array{path: string, code: string, message: string}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (DslViolation $violation): array => $violation->toArray(),
            $this->violations,
        );
    }

    /**
     * @param non-empty-list<DslViolation> $violations
     */
    private static function buildMessage(array $violations): string
    {
        if (count($violations) === 1) {
            return 'The design document is invalid. ' . $violations[0]->message;
        }

        $lines = [sprintf('The design document has %d problems:', count($violations))];

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
