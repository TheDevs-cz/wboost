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
use WBoost\Web\Value\MockupPageLayout;

#[Entity]
class ManualMockupPage
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[ManyToOne(inversedBy: 'pages')]
        #[JoinColumn(nullable: false, onDelete: "CASCADE")]
        readonly public Manual $manual,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public DateTimeImmutable $createdAt,

        #[Column]
        readonly public MockupPageLayout $layout,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public string $name,

        /**
         * @var array<int, string|null>
         */
        #[Column(type: Types::JSON)]
        public array $images,

        #[Column(options: ['default' => 0])]
        public int $position,
    ) {
    }

    /**
     * @param array<int, string|null> $images
     */
    public function edit(string $name, array $images): void
    {
        $this->name = $name;
        $this->images = $images;
    }

    public function sort(int $position): void
    {
        $this->position = $position;
    }
}
