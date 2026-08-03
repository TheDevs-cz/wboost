<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * Who a stored object belongs to, resolved to the owning project and its user
 * so the inventory can attribute bytes without joining at report time.
 *
 * All fields are nullable: an orphan whose project has since been deleted
 * cannot be attributed to anyone.
 */
readonly final class StorageOwner
{
    public function __construct(
        public null|string $projectId = null,
        public null|string $projectName = null,
        public null|string $ownerId = null,
        public null|string $ownerEmail = null,
    ) {
    }

    public function isKnown(): bool
    {
        return $this->projectId !== null;
    }
}
