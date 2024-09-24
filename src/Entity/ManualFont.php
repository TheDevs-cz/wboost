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
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\ManualFontType;

#[Entity]
class ManualFont
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[ManyToOne(fetch: 'EXTRA_LAZY')]
        #[JoinColumn(nullable: false, onDelete: "CASCADE")]
        readonly public Manual $manual,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne(fetch: 'EXTRA_LAZY')]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Font $font,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public ManualFontType $type,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $color,

        /** @var array<string> */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::JSON)]
        public array $fontFaces,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public DateTimeImmutable $createdAt,

        #[Column(options: ['default' => 0])]
        public int $position,
    ) {
    }

    public function sort(int $position): void
    {
        $this->position = $position;
    }
}
