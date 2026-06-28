<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Doctrine\ProjectSharingDoctrineType;
use WBoost\Web\Services\Slugify;
use WBoost\Web\Value\ProjectSharing;
use WBoost\Web\Value\SharingLevel;

#[Entity]
class Project
{
    /**
     * Legacy JSONB sharing array — kept in place for one release as a rollback
     * safety net while the data lives in the {@see ProjectShare} relation. No
     * longer read or written; see the Release-2 column-drop follow-up.
     *
     * @var array<ProjectSharing>
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: ProjectSharingDoctrineType::NAME, options: ['default' => '[]'])]
    public array $sharing = [];

    /** @var Collection<int, ProjectShare> */
    #[OneToMany(targetEntity: ProjectShare::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $shares;

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
        $this->shares = new ArrayCollection();
        $this->changeName($this->name);
    }

    public function edit(string $name): void
    {
        $this->changeName($name);;
    }

    public function getUserSharingLevel(User $user): null|SharingLevel
    {
        foreach ($this->shares as $share) {
            if ($share->user->id->equals($user->id)) {
                return $share->level;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, ProjectShare>
     */
    public function getShares(): Collection
    {
        return $this->shares;
    }

    public function share(User $user, SharingLevel $level, \DateTimeImmutable $now, null|User $by = null): void
    {
        // Never share a project with its own owner.
        if ($user->id->equals($this->owner->id)) {
            return;
        }

        foreach ($this->shares as $share) {
            if ($share->user->id->equals($user->id)) {
                $share->changeLevel($level);

                return;
            }
        }

        $this->shares->add(new ProjectShare(Uuid::uuid7(), $this, $user, $level, $now, $by));
    }

    public function unshare(User $user): void
    {
        foreach ($this->shares as $share) {
            if ($share->user->id->equals($user->id)) {
                $this->shares->removeElement($share);

                return;
            }
        }
    }

    private function changeName(string $name): void
    {
        $this->name = $name;
        $this->slug = Slugify::string($name);
    }
}
