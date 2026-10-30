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
        /** `text` (plain string) | `image` (gallery image id or `{imageId, hide}`). */
        public string $kind,
        public array $bindings,
    ) {
    }
}
