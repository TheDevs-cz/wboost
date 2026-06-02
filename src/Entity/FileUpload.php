<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\FileSource;

#[Entity]
class FileUpload
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: "CASCADE")]
        readonly public Project $project,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public \DateTimeImmutable $uploadedAt,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        readonly public FileSource $source,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        readonly public string $path,

        /**
         * Virtual folder this upload lives in, or `null` for the gallery root.
         * Mutable: the gallery's "move to folder" action re-points it. On the
         * DB side the FK is SET NULL so a force-deleted directory drops its
         * files to the root rather than cascading them away (the delete handler
         * normally re-parents them first).
         */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|FileDirectory $directory = null,
    ) {
    }

    public function moveToDirectory(null|FileDirectory $directory): void
    {
        $this->directory = $directory;
    }
}
