<?php

declare(strict_types=1);

namespace WBoost\Web\Message\AiScan;

use Ramsey\Uuid\UuidInterface;

/**
 * "Vytvořit šablonu": the proposal becomes a template with one variant
 * (architecture.md §4.6, §5.2 rule 14). Idempotent — a repeat answers with
 * the ids of the first.
 *
 * The handler answers `{templateId, variantId, elements, unfilledPictures, created}`.
 */
readonly final class AcceptTemplateScan
{
    public function __construct(
        public UuidInterface $scanId,
        public UuidInterface $userId,
    ) {
    }
}
