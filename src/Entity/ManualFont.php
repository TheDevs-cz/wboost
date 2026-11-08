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

        #[ManyToOne(fetch: 'EXTRA_LAZY', inversedBy: 'fonts')]
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

        #[Column(options: ['default' => 0])]
        public int $position,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public DateTimeImmutable $createdAt,

        /** @var array<string> */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::JSON)]
        public array $fontFaces = [],
    ) {
    }

    public function isPrimary(): bool
    {
        return $this->type === ManualFontType::Primary;
    }

    public function isSecondary(): bool
    {
        return $this->type === ManualFontType::Secondary;
    }

    public function edit(
        Font $font,
        ManualFontType $type,
        null|string $color,
    ): void
    {
        // A different font presents all of ITS cuts — the old font's names
        // mean nothing there, and a manual font without enabled cuts is not
        // shown in the manual at all (2026-11-08).
        if ($font->id->equals($this->font->id) === false) {
            $this->fontFaces = $font->faceNames();
        }

        $this->font = $font;
        $this->type = $type;
        $this->color = $color;
    }

    public function faceEnabled(string $faceName): bool
    {
        return in_array($faceName, $this->fontFaces, true);
    }

    /**
     * @param array<string> $fontFaces
     */
    public function enableFontFaces(array $fontFaces): void
    {
        $this->fontFaces = $fontFaces;
    }

    /**
     * A cut uploaded later to this font is switched on here too — the
     * designer turns cuts OFF on the Fonty page, so a new one shows by
     * default while the ones switched off stay off. Kept in the font's face
     * order. Call after the face is on `$this->font` (2026-11-08).
     */
    public function enableAddedFontFace(string $faceName): void
    {
        $this->fontFaces = array_values(array_filter(
            $this->font->faceNames(),
            fn (string $name): bool => $name === $faceName || in_array($name, $this->fontFaces, true),
        ));
    }

    public function dropFontFace(string $faceName): void
    {
        $this->fontFaces = array_values(array_filter(
            $this->fontFaces,
            static fn (string $name): bool => $name !== $faceName,
        ));
    }

    public function sort(int $position): void
    {
        $this->position = $position;
    }
}
