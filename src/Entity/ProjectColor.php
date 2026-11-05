<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\ManualColorType;

/**
 * One colour of the project palette ("Paleta barev", F7 —
 * `docs/redesign/palette-plan.md`). Manuals use it through
 * {@see ManualPaletteColor} links.
 *
 * Release R1 (P1a): ADDITIVE. `manual.detected_colors` / `custom_colors`
 * stay authoritative and nothing reads this table yet; every write path
 * re-projects the touched manual into it
 * ({@see \WBoost\Web\Services\Palette\ProjectManualColors}).
 */
#[Entity]
#[Index(name: 'idx_project_color_project_hex', columns: ['project_id', 'hex_key'])]
class ProjectColor
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        readonly public Project $project,

        /** As written by the designer, without `#`, case kept — custom links print it. */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public string $hex,

        /** Normalised `#rrggbb` (lowercase literal for a hex that does not normalise) — the dedupe key. */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public string $hexKey,

        /** D2: ONE role per project colour — every manual linking it shows it on the same page. */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|ManualColorType $role,

        /** @var null|array{null|string, null|string, null|string, null|string} */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
        public null|array $cmyk,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $pantone,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $ral,

        /** Palette order — seeded with today's computeColors walk (D5). */
        #[Column(options: ['default' => 0])]
        public int $position,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public DateTimeImmutable $createdAt,

        /** New "Název"; rendered only when set (RAL precedent). */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $name = null,
    ) {
    }
}
