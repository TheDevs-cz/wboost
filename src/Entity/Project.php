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
use WBoost\Web\Services\Slugify;
use WBoost\Web\Value\ProjectSharing;
use WBoost\Web\Value\SharingLevel;

#[Entity]
class Project
{
    /** @var array<ProjectSharing> */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: ProjectSharingDoctrineType::NAME, options: ['default' => '[]'])]
    public array $sharing = [];

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => ''])]
    public string $slug;

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
        $this->changeName($this->name);
    }

    public function edit(string $name): void
    {
        $this->changeName($name);;
    }

    public function getUserSharingLevel(User $user): null|SharingLevel
    {
        foreach ($this->sharing as $sharing) {
            if ($sharing->userId->equals($user->id)) {
                return $sharing->level;
            }
        }

        return null;
    }

    private function changeName(string $name): void
    {
        $this->name = $name;
        $this->slug = Slugify::string($name);
    }
}
