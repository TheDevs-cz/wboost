<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\Table;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\ExportChannel;
use WBoost\Web\Value\ExportedTemplateType;

/**
 * Immutable analytics record of a single template export / download.
 *
 * One row is written every time a social-network or custom-template variant is
 * exported to a PNG — both from the web fill page (download button) and the
 * service-to-service API. Used only for admin usage reporting.
 *
 * Everything needed for reporting (owner / project / template labels) is
 * **denormalised** onto the row on purpose: an export event is a fact about a
 * moment in time, so it must survive later deletion or renaming of the
 * project / template, and the usage report must aggregate without any joins.
 * There are deliberately no FK associations here.
 */
#[Entity]
#[Table(name: 'export_event')]
#[Index(name: 'idx_export_event_exported_at', columns: ['exported_at'])]
#[Index(name: 'idx_export_event_owner', columns: ['owner_id'])]
#[Index(name: 'idx_export_event_project', columns: ['project_id'])]
class ExportEvent
{
    public function __construct(
        #[Id]
        #[Column(type: UuidType::NAME, unique: true)]
        readonly public UuidInterface $id,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public DateTimeImmutable $exportedAt,

        #[Column(type: 'string', enumType: ExportedTemplateType::class)]
        readonly public ExportedTemplateType $templateType,

        #[Column(type: 'string', enumType: ExportChannel::class)]
        readonly public ExportChannel $channel,

        #[Column(type: UuidType::NAME)]
        readonly public UuidInterface $templateId,

        #[Column]
        readonly public string $templateName,

        #[Column(type: UuidType::NAME)]
        readonly public UuidInterface $variantId,

        #[Column(type: UuidType::NAME)]
        readonly public UuidInterface $projectId,

        #[Column]
        readonly public string $projectName,

        #[Column(type: UuidType::NAME)]
        readonly public UuidInterface $ownerId,

        #[Column]
        readonly public string $ownerEmail,

        #[Column(type: UuidType::NAME, nullable: true)]
        readonly public null|UuidInterface $triggeredByUserId,
    ) {
    }
}
