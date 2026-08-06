<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use WBoost\Web\Value\TemplateDimension;

/**
 * One DISTINCT dimension a project's template variants are drawn at, plus how
 * many variants use it.
 *
 * The count is what turns a flat list of sizes into a statement about the
 * brand: "this project designs at 1:1 (12 variants) and A4 (2)" tells an agent
 * which format is the house default, which "1:1, A4" does not.
 */
readonly final class TemplateDimensionUsage
{
    public function __construct(
        public TemplateDimension $dimension,
        public int $variantCount,
    ) {
    }
}
