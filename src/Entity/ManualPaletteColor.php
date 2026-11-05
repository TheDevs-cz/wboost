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
use WBoost\Web\Value\Color;
use WBoost\Web\Value\ManualColor;

/**
 * A manual's use of a {@see ProjectColor}, in the manual's colour order
 * (`position` — what `Manual::color(n)` indexes since R2: the logo segment
 * first, then the added colours). Written over DBAL by
 * {@see \WBoost\Web\Services\Palette\ManualPaletteWriter}.
 *
 * `logoHex` non-null = "Z logotypu": the literal colour string of the SVG,
 * kept verbatim because logo recolouring `str_ireplace`s it ("fff" ≠
 * "ffffff"). Unique per manual (partial index).
 *
 * The FK to `project_color` is ON DELETE CASCADE (since P1b,
 * `Version20261105193000`): the P1a NO ACTION broke the project-delete cascade
 * whenever Postgres happened to fire the `project_color` cascade before the
 * `manual` one (RI triggers run in trigger-name order). Deleting a single
 * palette colour that manuals still use is guarded by the app (P2).
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

        #[ManyToOne(inversedBy: 'paletteLinks')]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        readonly public Manual $manual,

        /** EAGER: joined whenever the manual's links load — the read model always needs it. */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne(fetch: 'EAGER')]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public ProjectColor $projectColor,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $logoHex,

        #[Column(options: ['default' => 0])]
        public int $position,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public DateTimeImmutable $createdAt,
    ) {
        $manual->addPaletteLink($this);
    }

    public function isFromLogo(): bool
    {
        return $this->logoHex !== null;
    }

    /**
     * This use of the palette colour as the manual read model shows it: a
     * logo link keeps the SVG's literal colour, an added colour prints the
     * palette HEX; role (D2, per project) and print data come from the palette.
     */
    public function manualColor(): ManualColor
    {
        $color = $this->projectColor;

        return new ManualColor(new Color($this->logoHex ?? $color->hex), $color->role, $color->pantone, $color->cmyk, $color->ral);
    }
}
