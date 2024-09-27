<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OrderBy;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[Entity]
class SocialNetworkCategory
{
    /** @var Collection<int, SocialNetworkTemplate>  */
    #[Immutable]
    #[OneToMany(targetEntity: SocialNetworkTemplate::class, mappedBy: 'category', fetch: 'EXTRA_LAZY')]
    #[OrderBy(['position' => 'ASC'])]
    public Collection $templates;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: "CASCADE")]
        readonly public Project $project,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public \DateTimeImmutable $createdAt,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public string $name,

        #[Column(options: ['default' => 0])]
        public int $position,

    ) {
        $this->templates = new ArrayCollection();
    }

    public function edit(string $name): void
    {
        $this->name = $name;
    }

    public function sort(int $position): void
    {
        $this->position = $position;
    }
}
