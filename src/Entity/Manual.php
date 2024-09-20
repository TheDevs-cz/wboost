<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use WBoost\Web\Doctrine\ColorsMappingDoctrineType;
use WBoost\Web\Doctrine\LogoDoctrineType;
use WBoost\Web\Doctrine\ManualColorsDoctrineType;
use WBoost\Web\Exceptions\InvalidColorHex;
use WBoost\Web\Repository\ManualDoctrineRepository;
use WBoost\Web\Services\Slugify;
use WBoost\Web\Value\Color;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\ColorMapping;
use WBoost\Web\Value\Logo;
use WBoost\Web\Value\ManualColor;
use WBoost\Web\Value\ManualType;

#[Entity(repositoryClass: ManualDoctrineRepository::class)]
class Manual
{
    /**
     * @var non-empty-array<string|null>
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSON, options: ['default' => '[]'])]
    public array $primaryColors;

    /**
     * @var array<string>
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSON, options: ['default' => '[]'])]
    public array $secondaryColors = [];

    /** @var array<ManualColor> */
    #[Column(type: ManualColorsDoctrineType::NAME, options: ['default' => '[]'])]
    private array $detectedColors = [];

    /** @var array<ManualColor> */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: ManualColorsDoctrineType::NAME, options: ['default' => '[]'])]
    public array $customColors = [];

    /**
     * @var array<ColorMapping>
     */
    #[Column(type: ColorsMappingDoctrineType::NAME, options: ['default' => '[]'])]
    public array $colorsMapping = [];

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: LogoDoctrineType::NAME, nullable: false)]
    public Logo $logo;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[ManyToOne(targetEntity: Font::class, fetch: 'EXTRA_LAZY')]
    #[JoinColumn(onDelete: 'SET NULL')]
    public null|Font $primaryFont = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[ManyToOne(targetEntity: Font::class, fetch: 'EXTRA_LAZY')]
    #[JoinColumn(onDelete: 'SET NULL')]
    public null|Font $secondaryFont = null;

    /** @var Collection<int, ManualMockupPage>  */
    #[Immutable]
    #[OneToMany(targetEntity: ManualMockupPage::class, mappedBy: 'manual', fetch: 'EXTRA_LAZY')]
    public Collection $pages;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => ''])]
    public string $slug;

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
        public ManualType $type,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public string $name,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $introImage,
    ) {
        $this->pages = new ArrayCollection();
        $this->logo = Logo::withoutImages();
        $this->changeName($this->name);

        /** @var non-empty-array<int, null> $emptyColors */
        $emptyColors = array_fill(0, $type->primaryColorsCount(), null);
        $this->primaryColors = $emptyColors;
    }

    public function edit(ManualType $type, string $name, null|string $introImage): void
    {
        $this->type = $type;
        $this->introImage = $introImage;
        $this->changeName($this->name);
    }

    public function editLogo(Logo $logo): void
    {
        $this->logo = $logo;
    }

    public function getPrimaryColor(int $number): null|Color
    {
        $colorHex = $this->primaryColors[$number-1] ?? null;

        if ($colorHex === null) {
            return null;
        }

        try {
            return new Color($colorHex);
        } catch (InvalidColorHex) {
            return null;
        }
    }

    public function primaryColorsCount(): int
    {
        return count(array_filter($this->primaryColors));
    }

    public function colorsCount(): int
    {
        return $this->primaryColorsCount() + count($this->secondaryColors);
    }

    /**
     * @param non-empty-array<null|string> $primaryColors
     * @param array<null|string> $secondaryColors
     * @param array<ColorMapping> $colorsMapping
     */
    public function editColors(
        array $primaryColors,
        array $secondaryColors,
        array $colorsMapping,
    ): void
    {
        $this->primaryColors = $primaryColors;
        $this->secondaryColors = array_values(array_filter($secondaryColors));
        $this->colorsMapping = $colorsMapping;
    }

    public function colorsMappedCorrectly(): bool
    {
        return false;
    }

    /**
     * @return array<Font>
     */
    public function getFonts(): array
    {
        $fonts = [];

        if ($this->primaryFont !== null) {
            $fonts[] = $this->primaryFont;
        }

        if ($this->secondaryFont !== null) {
            $fonts[] = $this->secondaryFont;
        }

        return $fonts;
    }

    public function fontsCount(): int
    {
        $count = 0;

        if ($this->primaryFont !== null) {
            $count++;
        }

        if ($this->secondaryFont !== null) {
            $count++;
        }

        return $count;
    }

    public function isBrandManual(): bool
    {
        return $this->type === ManualType::Brand;
    }

    public function pagesCount(): int
    {
        return $this->pages->count();
    }

    public function editFonts(null|Font $primaryFont, null|Font $secondaryFont): void
    {
        $this->primaryFont = $primaryFont;
        $this->secondaryFont = $secondaryFont;
    }

    /** @return array<ManualColor> */
    public function detectedColors(): array
    {
        /** @var array<string> $detectedColorsHex */
        $detectedColorsHex = [];

        /** @return array<ManualColor> */
        $detectedColors = [];

        foreach ($this->detectedColors as $detectedColor) {
            $detectedColors[] = $detectedColor;
            $detectedColorsHex[] = $detectedColor->color->hex;
        }

        foreach ($this->logo->getDetectedColors() as $detectedColor) {
            if (!in_array($detectedColor->hex, $detectedColorsHex)) {
                $detectedColors[] = new ManualColor($detectedColor, null);
                $detectedColorsHex[] = $detectedColor->hex;
            }
        }

        return $detectedColors;
    }

    private function changeName(string $name): void
    {
        $this->name = $name;
        $this->slug = Slugify::string($name);
    }
}
