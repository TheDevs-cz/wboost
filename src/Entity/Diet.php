<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\DietCode;

#[Entity]
class Diet
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Project $project,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public string $name,

        /** @var array<string> - array of DietCode values */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::JSON)]
        public array $codes = [],

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::SMALLINT, options: ['default' => 0])]
        public int $position = 0,
    ) {
    }

    /**
     * @param array<string> $codes
     */
    public function edit(string $name, array $codes): void
    {
        $this->name = $name;
        $this->codes = $codes;
    }

    public function sort(int $position): void
    {
        $this->position = $position;
    }

    /**
     * @return array<DietCode>
     */
    public function dietCodes(): array
    {
        return array_map(
            static fn(string $code): DietCode => DietCode::from($code),
            $this->codes,
        );
    }

    public function codesLabel(): string
    {
        return implode(', ', $this->codes);
    }
}
