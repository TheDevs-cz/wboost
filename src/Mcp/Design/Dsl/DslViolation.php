<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Dsl;

/**
 * One thing wrong with a design document, addressed by its **path**.
 *
 * The path is the JSON pointer an agent can act on directly — `canvas.width`,
 * `elements[2].font`, `elements[0].at.col`, `elements[3].input.maxLength`. It
 * is the difference between an error the model fixes in one turn and one it
 * guesses at.
 */
readonly final class DslViolation
{
    public function __construct(
        /** Dotted/bracketed path into the document, e.g. `elements[2].at.col`. */
        public string $path,
        public DslErrorCode $code,
        /**
         * Human (English) sentence, already containing the path so it reads
         * standalone in a joined list. Ends with a full stop.
         */
        public string $message,
    ) {
    }

    /**
     * @return array{path: string, code: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'code' => $this->code->value,
            'message' => $this->message,
        ];
    }
}
