<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Template;

use Ramsey\Uuid\UuidInterface;

readonly final class CopyTemplate
{
    /**
     * `$variantIds` optionally pre-assigns the copies' ids (original variant
     * id → new id), so a caller that must address one specific copy right
     * after — "Duplikovat stránku" attaching the copy of the page variant —
     * knows it without guessing. Unlisted variants get fresh ids.
     *
     * @param array<string, UuidInterface> $variantIds
     */
    public function __construct(
        public UuidInterface $originalTemplateId,
        public UuidInterface $newTemplateId,
        public array $variantIds = [],
    ) {
    }
}
