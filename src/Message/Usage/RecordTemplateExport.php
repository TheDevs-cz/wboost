<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Usage;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\ExportChannel;
use WBoost\Web\Value\ExportedTemplateType;

/**
 * Records that a template variant was exported. Carries the already-resolved
 * denormalised labels so the handler does no entity lookups — see
 * {@see \WBoost\Web\Entity\ExportEvent} for why everything is denormalised.
 */
readonly final class RecordTemplateExport
{
    public function __construct(
        public ExportedTemplateType $templateType,
        public ExportChannel $channel,
        public UuidInterface $templateId,
        public string $templateName,
        public UuidInterface $variantId,
        public UuidInterface $projectId,
        public string $projectName,
        public UuidInterface $ownerId,
        public string $ownerEmail,
        public null|UuidInterface $triggeredByUserId,
    ) {
    }
}
