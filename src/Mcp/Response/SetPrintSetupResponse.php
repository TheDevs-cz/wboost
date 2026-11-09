<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * `set_print_setup`'s answer: the variant's print setup as it is now, in the
 * same `dimension` shape describe_variant reports.
 */
readonly final class SetPrintSetupResponse
{
    public function __construct(
        public string $variantId,
        public string $templateName,
        public string $projectName,
        /** Grouped variants keep a print setup of their own — nothing propagated to the group's other dimensions. */
        public bool $grouped,
        public VariantDimensionResponse $dimension,
        public string $status,
    ) {
    }
}
