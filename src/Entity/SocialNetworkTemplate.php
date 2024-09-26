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
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[Entity]
class SocialNetworkTemplate
{
    /** @var Collection<int, SocialNetworkTemplateVariant>  */
    #[Immutable]
    #[OneToMany(targetEntity: SocialNetworkTemplateVariant::class, mappedBy: 'template', fetch: 'EXTRA_LAZY')]
    private Collection $variants;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: "CASCADE")]
        readonly public Project $project,

        #[ManyToOne]
        #[JoinColumn(onDelete: "SET NULL")]
        readonly public null|SocialNetworkCategory $category,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public \DateTimeImmutable $createdAt,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public string $name,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $image,
    ) {
        $this->variants = new ArrayCollection();
    }

    public function edit(string $name, null|string $imagePath): void
    {
        $this->name = $name;
        $this->image = $imagePath;
    }

    /**
     * @return array<SocialNetworkTemplateVariant>
     */
    public function variants(): array
    {
        return $this->variants->toArray();
    }
}
