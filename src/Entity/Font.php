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
use WBoost\Web\Doctrine\FontFacesDoctrineType;
use WBoost\Web\Exceptions\FontAlreadyHasFontFace;
use WBoost\Web\Value\FontFace;

#[Entity]
class Font
{
    /**
     * @var array<FontFace>
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: FontFacesDoctrineType::NAME)]
    public array $faces;

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

        FontFace $fontFace,
    ) {
        $this->faces = [$fontFace];
    }

    /**
     * @throws FontAlreadyHasFontFace
     */
    public function addFontFace(FontFace $fontFace): void
    {
        foreach ($this->faces as $existingFontFace) {
            if ($existingFontFace->name === $fontFace->name) {
                throw new FontAlreadyHasFontFace();
            }
        }

        $this->faces[] = $fontFace;
    }
}
