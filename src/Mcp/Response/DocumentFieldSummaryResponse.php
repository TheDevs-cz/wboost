<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

readonly final class DocumentFieldSummaryResponse
{
    /**
     * @param list<array{pageId: string, inputId: string}> $bindings
     */
    public function __construct(
        public string $id,
        public string $name,
        /** `text` (an input value, see textMode) | `image` (gallery image id or `{imageId, hide}`) | `shape` (`#rrggbb`, "" = designed). */
        public string $kind,
        public array $bindings,
        /** Text: `plain` (string) | `rich` ({runs}) | `list` ({runs, lines}) | `checklist` — what every bound input accepts. Null otherwise. */
        public null|string $textMode = null,
    ) {
    }
}
