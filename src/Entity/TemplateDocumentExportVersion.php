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
use WBoost\Web\Value\DocumentFillValues;
use WBoost\Web\Value\ExportChannel;

/**
 * "Historie exportů" + "Uložit rozpracované" of a multi-page document — the
 * {@see TemplateExportVersion} semantics (dedup by content hash, drafts
 * promoted on export, names, pins exempt from the {@see MAX_VERSIONS} prune)
 * over a DOCUMENT fill ({@see DocumentFillValues}: per-page values).
 *
 * Its own entity on purpose: the single-subject version (variant XOR group,
 * `template` NOT NULL, the `ExportFillValues` column type, the seeder, the API
 * view and the history page) is read in many places that all assume one
 * variant's input space; a document fill is a map of those. Deleting the
 * document CASCADEs its history; deleting a user anonymises it.
 */
#[Entity]
#[Index(name: 'idx_document_export_version_document', columns: ['document_id', 'last_exported_at'])]
class TemplateDocumentExportVersion implements CuratableExportVersion
{
    public const int MAX_VERSIONS = 100;
    public const int NAME_MAX_LENGTH = 120;

    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $lastExportedAt;

    #[Column(type: Types::INTEGER)]
    public int $exportCount = 1;

    #[Column(type: Types::BOOLEAN, options: ['default' => false])]
    public bool $draft = false;

    #[Column(length: self::NAME_MAX_LENGTH, nullable: true)]
    public null|string $name = null;

    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $pinnedAt = null;

    /**
     * Canonical {@see DocumentFillValues::toArray()}.
     *
     * @var array<array-key, mixed>
     */
    #[Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $fill;

    #[Column(length: 64)]
    public private(set) string $fillHash;

    public function __construct(
        #[Id]
        #[Column(type: UuidType::NAME, unique: true)]
        readonly public UuidInterface $id,

        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: "CASCADE")]
        readonly public TemplateDocument $document,

        #[ManyToOne]
        #[JoinColumn(onDelete: "SET NULL")]
        public null|User $exportedBy,

        #[Column(type: 'string', enumType: ExportChannel::class)]
        public ExportChannel $channel,

        DocumentFillValues $fill,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public DateTimeImmutable $createdAt,
    ) {
        $this->lastExportedAt = $createdAt;
        $this->fill = $fill->toArray();
        $this->fillHash = $fill->hash();
    }

    public function fill(): DocumentFillValues
    {
        return DocumentFillValues::fromArray($this->fill);
    }

    public function bump(DateTimeImmutable $exportedAt, null|User $exportedBy, ExportChannel $channel): void
    {
        $this->lastExportedAt = $exportedAt;
        $this->exportCount++;
        $this->exportedBy = $exportedBy;
        $this->channel = $channel;
    }

    public function markDraft(): void
    {
        $this->draft = true;
        $this->exportCount = 0;
    }

    public function saveDraft(DocumentFillValues $fill, DateTimeImmutable $savedAt, null|User $savedBy): void
    {
        $this->fill = $fill->toArray();
        $this->fillHash = $fill->hash();
        $this->lastExportedAt = $savedAt;
        $this->exportedBy = $savedBy;
    }

    public function promoteToExport(DocumentFillValues $fill, DateTimeImmutable $exportedAt, null|User $exportedBy, ExportChannel $channel): void
    {
        $this->saveDraft($fill, $exportedAt, $exportedBy);
        $this->draft = false;
        $this->exportCount = 1;
        $this->channel = $channel;
    }

    public function rename(null|string $name): void
    {
        $name = $name === null ? '' : trim($name);
        $this->name = $name === '' ? null : mb_substr($name, 0, self::NAME_MAX_LENGTH);
    }

    public function pin(DateTimeImmutable $pinnedAt): void
    {
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
