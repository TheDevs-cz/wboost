<?php

declare(strict_types=1);

namespace WBoost\Web\Message\TemplateGroup;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\GroupCustomVariantSelection;
use WBoost\Web\Value\GroupSocialVariantSelection;

readonly final class CreateTemplateGroup
{
    /**
     * @param list<GroupSocialVariantSelection> $socialVariants
     * @param list<GroupCustomVariantSelection> $customVariants
     */
    public function __construct(
        public UuidInterface $projectId,
        public UuidInterface $groupId,
        public string $name,
        public null|UuidInterface $socialCategoryId,
        public null|UuidInterface $customCategoryId,
        public array $socialVariants,
        public array $customVariants,
    ) {
    }
}
