<?php

declare(strict_types=1);

namespace WBoost\Web\Message\AiScan;

use Ramsey\Uuid\UuidInterface;

/**
 * One fix of the review, or one picture decision (ux.md S4 / S4b). The fix
 * types and their fields: docs/architecture/ai-scan.md § "Fixes".
 *
 * `fix: null` re-renders the proposal's picture and changes nothing else
 * ("Náhled se nepodařilo obnovit · Zkusit znovu").
 *
 * The handler answers `{previewStale: bool, assigned: null|int}`.
 */
readonly final class ApplyAiScanFix
{
    /**
     * @param null|array<string, mixed> $fix `{type, …}`
     * @param list<string> $uploadedFileIds gallery files this request uploaded for the scan
     * @param bool $render false = save only (a caller applying several fixes in a row renders once at the end; the preview is then stale)
     */
    public function __construct(
        public UuidInterface $scanId,
        public UuidInterface $userId,
        public null|array $fix,
        public array $uploadedFileIds = [],
        public bool $render = true,
    ) {
    }
}
