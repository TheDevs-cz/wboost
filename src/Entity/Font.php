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
     * The exact `fontFamily` string one of this font's faces is addressed by
     * everywhere outside the font management screen: `"Rubik (Rubik Bold)"`.
     *
     * It is a WIRE value, not a label. Canvas objects store it verbatim, the
     * render template registers its `@font-face` under it, the rich-text export
     * whitelist compares against it, and the MCP `get_context` tool hands the
     * list to an agent that must reproduce it byte for byte (an unknown family
     * is a hard error at design-compile time). Hence one implementation: a
     * second `sprintf('%s (%s)', …)` somewhere else is a family string waiting
     * to drift by a space.
     */
    public function faceFamily(FontFace $face): string
    {
        return sprintf('%s (%s)', $this->name, $face->name);
    }

    /**
     * A new face lands in WEIGHT order — uprights Thin→Black, then italics —
     * as long as the existing faces still sit in that order; once the
     * designer has dragged the list into an order of their own, new faces
     * simply append so the drag order is never silently undone.
     *
     * @throws FontAlreadyHasFontFace
     */
    public function addFontFace(FontFace $fontFace): void
    {
        foreach ($this->faces as $existingFontFace) {
            if ($existingFontFace->name === $fontFace->name) {
                throw new FontAlreadyHasFontFace();
            }
        }

        if (!self::isWeightOrdered($this->faces)) {
            $this->faces[] = $fontFace;

            return;
        }

        $faces = [];
        $inserted = false;
        foreach ($this->faces as $existingFontFace) {
            if (!$inserted && self::weightKey($existingFontFace) > self::weightKey($fontFace)) {
                $faces[] = $fontFace;
                $inserted = true;
            }
            $faces[] = $existingFontFace;
        }
        if (!$inserted) {
            $faces[] = $fontFace;
        }

        $this->faces = $faces;
    }

    /**
     * @param array<FontFace> $faces
     */
    private static function isWeightOrdered(array $faces): bool
    {
        $previous = null;
        foreach ($faces as $face) {
            $key = self::weightKey($face);
            if ($previous !== null && $key < $previous) {
                return false;
            }
            $previous = $key;
        }

        return true;
    }

    /** Uprights before italics, lighter before heavier. */
    private static function weightKey(FontFace $face): int
    {
        return ($face->isItalic() ? 10000 : 0) + $face->weight;
    }

    public function removeFontFace(string $fontFaceName): void
    {
        $faces = [];

        foreach ($this->faces as $existingFontFace) {
            if ($existingFontFace->name === $fontFaceName) {
                continue;
            }

            $faces[] = $existingFontFace;
        }

        $this->faces = $faces;
    }

    /**
     * @param array<string> $faceNames
     */
    public function sortFaces(array $faceNames): void
    {
        $newFaces = [];
        $faces = [];

        foreach ($this->faces as $face) {
            $faces[$face->name] = $face;
        }

        foreach ($faceNames as $faceName) {
            $newFaces[] = $faces[$faceName];
        }

        $this->faces = $newFaces;
    }
}
