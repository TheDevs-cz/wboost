<?php

declare(strict_types=1);

namespace WBoost\Web\Message\TemplateGroup;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\GroupCustomVariantSelection;
use WBoost\Web\Value\GroupSocialVariantSelection;

readonly final class CreateTemplateGroup
{
    /**
     * At most one of the source-variant ids may be set ("create from existing
     * template"): every new variant is then seeded with that variant's design
     * projected to its own dimension, and selections without an uploaded
     * background fall back to a copy of the source variant's background.
     *
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
        public null|UuidInterface $sourceSocialVariantId = null,
        public null|UuidInterface $sourceCustomVariantId = null,
    ) {
    }
}
