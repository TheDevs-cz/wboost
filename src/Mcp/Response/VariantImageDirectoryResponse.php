<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * One gallery folder an image slot may be filled from, already resolved
 * against the project's real folders — a folder the designer picked and
 * somebody later deleted simply is not here.
 */
readonly final class VariantImageDirectoryResponse
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
