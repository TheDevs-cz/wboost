<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

/**
 * What one project's template library looks like in aggregate — see
 * {@see GetProjectTemplateStats}.
 */
readonly final class ProjectTemplateStats
{
    /**
     * @param list<TemplateDimensionUsage> $dimensions distinct dimensions, most-used first
     */
    public function __construct(
        public int $templateCount,
        public int $variantCount,
        public array $dimensions,
    ) {
    }

    /**
     * A project with no templates at all. Returned instead of a missing key so
     * callers never have to decide what an absent project means.
     */
    public static function none(): self
    {
        return new self(0, 0, []);
    }
}
