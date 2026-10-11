<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Template;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\ExportChannel;
use WBoost\Web\Value\ExportFillValues;

/**
 * A successful export happened — snapshot its fill values as a re-usable
 * version ("Historie exportů"). Exactly one of `variantId` / `groupId` is set:
 * the fill surface the version belongs to and can later seed again.
 */
readonly final class RecordTemplateExportVersion
{
    public function __construct(
        public null|UuidInterface $variantId,
        public null|UuidInterface $groupId,
        public null|UuidInterface $exportedByUserId,
        public ExportChannel $channel,
        public ExportFillValues $fillValues,
        /**
         * The draft the fill page had loaded / saved (raw, client-supplied):
         * when it is a draft of this subject, the export PROMOTES it instead
         * of adding a row of its own.
         */
        public null|string $draftVersionId = null,
    ) {
    }
}
