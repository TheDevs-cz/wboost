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
 *
 * Users curate the list: a version can be NAMED (the name replaces the export
 * date wherever the version is listed) and PINNED (sorts first everywhere and
 * is exempt from history pruning — the way back to "that one fill" that would
 * otherwise scroll out of reach). Both are shared per template, not per user:
 * the history is one list for everyone who can fill the surface.
 */
#[Entity]
#[Index(name: 'idx_export_version_variant', columns: ['variant_id', 'last_exported_at'])]
#[Index(name: 'idx_export_version_group', columns: ['group_id', 'last_exported_at'])]
#[Index(name: 'idx_export_version_template', columns: ['template_id', 'last_exported_at'])]
class TemplateExportVersion
{
    public const int NAME_MAX_LENGTH = 120;

    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $lastExportedAt;

    #[Column(type: Types::INTEGER)]
    public int $exportCount = 1;

    /**
     * Optional user-given label ("Letní kampaň – finální"). Null = unnamed,
     * listed under its export date.
     */
    #[Column(length: self::NAME_MAX_LENGTH, nullable: true)]
    public null|string $name = null;

    /**
     * When the version was pinned; null = not pinned. Pinned versions list
     * first (most recently pinned on top) and are never pruned.
     */
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $pinnedAt = null;

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

    /**
     * Blank clears the name (back to the date label); anything longer than
     * the column is cut, never rejected — a name is a convenience, not data.
     */
    public function rename(null|string $name): void
    {
        $trimmed = trim((string) $name);

        $this->name = $trimmed === '' ? null : mb_substr($trimmed, 0, self::NAME_MAX_LENGTH);
    }

    public function pin(DateTimeImmutable $pinnedAt): void
    {
        // Re-pinning keeps the original position in the pinned section.
        $this->pinnedAt ??= $pinnedAt;
    }

    public function unpin(): void
    {
        $this->pinnedAt = null;
    }

    public function isPinned(): bool
    {
        return $this->pinnedAt !== null;
    }
}
