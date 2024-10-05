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
use WBoost\Web\Value\TemplateDimension;

#[Entity]
class SocialNetworkTemplate
{
    /** @var Collection<int, SocialNetworkTemplateVariant>  */
    #[Immutable]
    #[OneToMany(targetEntity: SocialNetworkTemplateVariant::class, mappedBy: 'template', fetch: 'EAGER')]
    private Collection $variants;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: "CASCADE")]
        readonly public Project $project,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne(inversedBy: 'templates')]
        #[JoinColumn(onDelete: "SET NULL")]
        public null|SocialNetworkCategory $category,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public \DateTimeImmutable $createdAt,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public string $name,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $image,

        #[Column(options: ['default' => 0])]
        public int $position,
    ) {
        $this->variants = new ArrayCollection();
    }

    public function edit(
        null|SocialNetworkCategory $category,
        string $name,
        null|string $imagePath,
    ): void
    {
        $this->category = $category;
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

    /**
     * @return array<SocialNetworkTemplateVariant>
     */
    public function dimensionVariants(TemplateDimension $dimension): array
    {
        $variants = [];

        foreach ($this->variants() as $variant) {
            if ($variant->dimension === $dimension) {
                $variants[] = $variant;
            }
        }

        return $variants;
    }

    public function sort(int $position): void
    {
        $this->position = $position;
    }
}
