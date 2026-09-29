<?php

declare(strict_types=1);

namespace WBoost\Web\Api\ExportVersions;

final class ExportVersionUpdateRequest
{
    /**
     * New label (trimmed, max 120 chars — longer is cut). `null` or blank
     * clears it. Omit the key to leave the name untouched.
     */
    public null|string $name = null;

    /** true pins (re-pinning keeps the original pin time), false unpins. Omit to leave as is. */
    public null|bool $pinned = null;
}
