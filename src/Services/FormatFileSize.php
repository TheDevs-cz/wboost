<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

/**
 * Human-readable byte counts for the storage report. Binary units (1024),
 * matching how the upload limits are expressed.
 */
readonly final class FormatFileSize
{
    public function __invoke(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['kB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return number_format($value, $value >= 100 ? 0 : 1, ',', ' ') . ' ' . $units[$unit];
    }
}
