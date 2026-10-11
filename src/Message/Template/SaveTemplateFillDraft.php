<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Template;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\ExportFillValues;

/**
 * "Uložit rozpracované" on a fill page: store the form as a DRAFT version —
 * no render, so it works even while the export is blocked. `versionId` is the
 * draft to overwrite when it exists (the page's own draft), otherwise the id
 * of the new one. Exactly one of `variantId` / `groupId` is set.
 */
readonly final class SaveTemplateFillDraft
{
    public function __construct(
        public UuidInterface $versionId,
        public null|UuidInterface $variantId,
        public null|UuidInterface $groupId,
        public null|UuidInterface $savedByUserId,
        public ExportFillValues $fillValues,
    ) {
    }
}
