<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Entity\TemplateGroup;

readonly final class TemplateGroupListItem
{
    /**
     * @param list<SocialNetworkTemplateVariant> $socialVariants
     * @param list<CustomTemplateVariant> $customVariants
     */
    public function __construct(
        public TemplateGroup $group,
        public array $socialVariants,
        public array $customVariants,
    ) {
    }

    public function variantsCount(): int
    {
        return count($this->socialVariants) + count($this->customVariants);
    }
}
