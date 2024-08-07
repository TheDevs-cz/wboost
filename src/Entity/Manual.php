<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use WBoost\Web\Value\Color;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\ManualType;

#[Entity]
class Manual
{
    /**
     * @var array<string>
     */
    #[Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $colors = [];

    /**
     * @var array<array{source: string, target: string}>
     */
    #[Column(type: Types::JSON, options: ['default' => '[]'])]
    public array $colorMapping = [];

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $color1 = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $color2 = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $color3 = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $color4 = null;

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
        readonly public UuidInterface $id,

        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        readonly public Project $project,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public \DateTimeImmutable $createdAt,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public ManualType $type,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public string $name,
    ) {
    }

    public function edit(ManualType $type, string $name): void
    {
        $this->type = $type;
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

    /**
     * @return array<Color>
     */
    public function colors(): array
    {
        return array_map(
            fn(string $hex): Color => new Color($hex),
            $this->colors,
        );
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
        $logos = array_values(array_filter([
            $this->logoHorizontal,
            $this->logoHorizontalWithClaim,
            $this->logoSymbol,
            $this->logoVertical,
            $this->logoVerticalWithClaim,
        ]));

        return $logos[0] ?? null;
    }

    /**
     * @param array<string> $colors
     */
    public function addColors(array $colors): void
    {
        foreach ($colors as $color) {
            if (!in_array($color, $this->colors, true)) {
                $this->colors[] = $color;
            }
        }
    }

    /**
     * @param array<string, string> $mapping
     */
    public function mapColors(
        null|string $color1,
        null|string $color2,
        null|string $color3,
        null|string $color4,
        array $mapping,
    ): void
    {
        $this->color1 = $color1;
        $this->color2 = $color2;
        $this->color3 = $color3;
        $this->color4 = $color4;

        $colorMapping = [];

        foreach ($mapping as $color => $target) {
            if ($target !== '') {
                $colorMapping[] = ['source' => strtolower($color), 'target' => $target];
            }
        }

        $this->colorMapping = $colorMapping;
    }

    public function getMappedColorTarget(string $color): null|string
    {
        $color = strtolower($color);

        foreach ($this->colorMapping as $mapping) {
            if ($color === $mapping['source']) {
                return $mapping['target'];
            }
        }

        return null;
    }

    public function colorsMappedCorrectly(): bool
    {
        return false;
    }
}