<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\SharingLevel;

#[Entity]
#[Table(name: 'project_share')]
#[UniqueConstraint(columns: ['project_id', 'user_id'])]
class ProjectShare
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[ManyToOne(inversedBy: 'shares')]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        readonly public Project $project,

        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        readonly public User $user,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: 'string', enumType: SharingLevel::class)]
        public SharingLevel $level,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public DateTimeImmutable $sharedAt,

        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        readonly public null|User $sharedBy = null,
    ) {
    }

    public function changeLevel(SharingLevel $level): void
    {
        $this->level = $level;
    }
}
