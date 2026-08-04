<?php

declare(strict_types=1);

namespace WBoost\Web\Message\TemplateGroup;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\GroupVariantSelection;

readonly final class CreateTemplateGroup
{
    /**
     * The source-variant id may be set ("create from existing template"):
     * every new variant is then seeded with that variant's design projected
     * to its own dimension, and selections without an uploaded background
     * fall back to a copy of the source variant's background.
     *
     * @param list<GroupVariantSelection> $variants
     */
    public function __construct(
        public UuidInterface $projectId,
        public UuidInterface $groupId,
        public string $name,
        public null|UuidInterface $categoryId,
        public array $variants,
        public null|UuidInterface $sourceVariantId = null,
    ) {
    }
}
