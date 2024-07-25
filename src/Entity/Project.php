<?php

declare(strict_types=1);

namespace BrandManuals\Web\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[Entity]
class Project
{
    /**
     * @var array<string>
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSON, options: ['default' => '[]'])]
    public array $colors = [];

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $logoHorizontal = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $logoVertical = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $logoHorizontalWithClaim = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $logoVerticalWithClaim = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $logoSymbol = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public string $name,

        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public function edit(string $name): void
    {
        $this->name = $name;
    }

    public function updateImages(
        null|string $logoHorizontal,
        null|string $logoVertical,
        null|string $logoHorizontalWithClaim,
        null|string $logoVerticalWithClaim,
        null|string $logoSymbol,
    ): void {
        $this->logoHorizontal = $logoHorizontal;
        $this->logoVertical = $logoVertical;
        $this->logoHorizontalWithClaim = $logoHorizontalWithClaim;
        $this->logoVerticalWithClaim = $logoVerticalWithClaim;
        $this->logoSymbol = $logoSymbol;
    }

    public function logosCount(): int
    {
        $logos = array_filter([
            $this->logoHorizontal,
            $this->logoVertical,
            $this->logoHorizontalWithClaim,
            $this->logoVerticalWithClaim,
            $this->logoSymbol,
        ]);

        return count($logos);
    }

    public function introLogo(): null|string
    {
        $logos = array_filter([
            $this->logoHorizontal,
            $this->logoHorizontalWithClaim,
            $this->logoSymbol,
            $this->logoVertical,
            $this->logoVerticalWithClaim,
        ]);

        return $logos[0] ?? null;
    }
}
