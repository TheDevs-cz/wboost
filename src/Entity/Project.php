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
use WBoost\Web\Doctrine\ProjectSharingDoctrineType;
use WBoost\Web\Value\ProjectSharing;

#[Entity]
class Project
{
    /** @var array<ProjectSharing> */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: ProjectSharingDoctrineType::NAME, options: ['default' => '[]'])]
    public array $sharing = [];

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: "CASCADE")]
        readonly public User $owner,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public \DateTimeImmutable $createdAt,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public string $name,
    ) {
    }

    public function edit(string $name): void
    {
        $this->name = $name;
    }

    public function getSharingWithUser(User $user): null|ProjectSharing
    {
        foreach ($this->sharing as $sharing) {
            if ($sharing->userId->equals($user->id)) {
                return $sharing;
            }
        }

        return null;
    }
}
