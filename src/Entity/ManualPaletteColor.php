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
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * A manual's use of a {@see ProjectColor}, in the manual's colour order
 * (`position` — what `Manual::color(n)` will index in R2: the logo segment
 * first, then the added colours).
 *
 * `logoHex` non-null = "Z logotypu": the literal colour string of the SVG,
 * kept verbatim because logo recolouring `str_ireplace`s it ("fff" ≠
 * "ffffff"). Unique per manual (partial index).
 *
 * The FK to `project_color` is NO ACTION on purpose (checked at statement
 * end): RESTRICT would break the project-delete cascade, which removes the
 * links (via manual) and the palette in one statement. Deleting a single
 * palette colour is guarded by the app.
 */
#[Entity]
#[UniqueConstraint(name: 'uniq_manual_palette_color_logo_hex', columns: ['manual_id', 'logo_hex'], options: ['where' => '(logo_hex IS NOT NULL)'])]
class ManualPaletteColor
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        readonly public Manual $manual,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public ProjectColor $projectColor,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $logoHex,

        #[Column(options: ['default' => 0])]
        public int $position,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public DateTimeImmutable $createdAt,
    ) {
    }

    public function isFromLogo(): bool
    {
        return $this->logoHex !== null;
    }
}
