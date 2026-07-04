<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\FileSource;

/**
 * A virtual, filesystem-like folder for organizing a project's {@see FileUpload}s.
 *
 * Directories are pure metadata — they do NOT correspond to any physical path
 * in object storage (uploads still live at `file-upload/{projectId}/{id}.ext`).
 * Nesting is modeled with a nullable self-reference (`parent === null` means
 * the directory sits at the gallery root). Scoped per project + {@see FileSource}
 * so each asset "kind" gets its own independent tree.
 */
#[Entity]
class FileDirectory
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        readonly public Project $project,

        #[Immutable]
        #[Column]
        public FileSource $source,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public string $name,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'CASCADE')]
        public null|FileDirectory $parent,

        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public \DateTimeImmutable $createdAt,
    ) {
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }
}
