<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Doctrine\ExportFillValuesDoctrineType;
use WBoost\Web\Value\ExportChannel;
use WBoost\Web\Value\ExportFillValues;

/**
 * One re-usable snapshot of a fill: the placeholder values a successful export
 * ran with, so the user can come back and load exactly what they exported
 * before ("Historie exportů" on the fill pages).
 *
 * Unlike {@see ExportEvent} (immutable denormalised analytics), this is LIVE
 * functional data: it references its variant / group / user by FK because a
 * version is only useful while its fill surface still exists — deleting the
 * variant, group or template cascades the history away, deleting the user only
 * anonymises it.
 *
 * Exactly one of `variant` / `group` is set: a single-variant export snapshots
 * per-variant values, a group export (ZIP or per-dimension PNG) snapshots the
 * group fill form — shared texts/hides/picks plus per-dimension placements.
 *
 * Identical fills DEDUPLICATE: `fillValuesHash` is a content hash of the
 * canonicalised values, and re-exporting the same fill bumps `lastExportedAt`
 * / `exportCount` / `exportedBy` / `channel` on the existing row instead of
 * creating a new one — the history stays a list of DISTINCT fills, freshest
 * first.
 */
#[Entity]
#[Index(name: 'idx_export_version_variant', columns: ['variant_id', 'last_exported_at'])]
#[Index(name: 'idx_export_version_group', columns: ['group_id', 'last_exported_at'])]
#[Index(name: 'idx_export_version_template', columns: ['template_id', 'last_exported_at'])]
class TemplateExportVersion
{
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $lastExportedAt;

    #[Column(type: Types::INTEGER)]
    public int $exportCount = 1;

    public function __construct(
        #[Id]
        #[Column(type: UuidType::NAME, unique: true)]
        readonly public UuidInterface $id,

        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: "CASCADE")]
        readonly public Template $template,

        #[ManyToOne]
        #[JoinColumn(onDelete: "CASCADE")]
        readonly public null|TemplateVariant $variant,

        #[ManyToOne]
        #[JoinColumn(onDelete: "CASCADE")]
        readonly public null|TemplateGroup $group,

        #[ManyToOne]
        #[JoinColumn(onDelete: "SET NULL")]
        public null|User $exportedBy,

        #[Column(type: 'string', enumType: ExportChannel::class)]
        public ExportChannel $channel,

        #[Column(type: ExportFillValuesDoctrineType::NAME)]
        readonly public ExportFillValues $fillValues,

        #[Column(length: 64)]
        readonly public string $fillValuesHash,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public DateTimeImmutable $createdAt,
    ) {
        $this->lastExportedAt = $createdAt;
    }

    /**
     * The same fill was exported again: refresh recency, count and attribution
     * without minting a duplicate history entry.
     */
    public function bump(DateTimeImmutable $exportedAt, null|User $exportedBy, ExportChannel $channel): void
    {
        $this->lastExportedAt = $exportedAt;
        $this->exportCount++;
        $this->exportedBy = $exportedBy;
        $this->channel = $channel;
    }
}
