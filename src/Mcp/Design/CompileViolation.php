<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One reason a well-formed design document cannot be compiled against THIS
 * project, addressed by the same JSON pointer
 * {@see \WBoost\Web\Mcp\Design\Dsl\DslViolation} uses (`elements[2].font`) so
 * an agent reads one path vocabulary across both stages.
 *
 * {@see $allowed} is what makes the error self-correcting: the export API's
 * `font_not_allowed` already ships its `allowedFonts` list for exactly this
 * reason, and an agent told *"use one of these"* fixes the design in the same
 * turn instead of guessing at a second one.
 */
#[Exclude]
readonly final class CompileViolation
{
    /**
     * @param string $path dotted/bracketed path into the document
     * @param string $message human (English) sentence, already containing the
     *        path so it reads standalone in a joined list; ends with a full stop
     * @param list<string> $allowed the values that WOULD have worked, when the
     *        set is knowable and small enough to be useful; empty otherwise
     */
    public function __construct(
        public string $path,
        public CompileErrorCode $code,
        public string $message,
        public array $allowed = [],
    ) {
    }

    /**
     * @return array{path: string, code: string, message: string, allowed: list<string>}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'code' => $this->code->value,
            'message' => $this->message,
            'allowed' => $this->allowed,
        ];
    }
}
