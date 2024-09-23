<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use WBoost\Web\Doctrine\LogoDoctrineType;
use WBoost\Web\Doctrine\ManualColorsDoctrineType;
use WBoost\Web\Exceptions\MissingManualColor;
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
use WBoost\Web\Value\DefaultLogoColors;
use WBoost\Web\Value\Logo;
use WBoost\Web\Value\LogoColorVariant;
use WBoost\Web\Value\LogoTypeVariant;
use WBoost\Web\Value\ManualColor;
use WBoost\Web\Value\ManualColorType;
use WBoost\Web\Value\ManualType;

#[Entity(repositoryClass: ManualDoctrineRepository::class)]
class Manual
{
    /** @var array<ManualColor> */
    #[Column(type: ManualColorsDoctrineType::NAME, options: ['default' => '[]'])]
    private array $detectedColors = [];

    /** @var array<ManualColor> */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: ManualColorsDoctrineType::NAME, options: ['default' => '[]'])]
    public array $customColors = [];

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

    /**
     * @throws MissingManualColor
     */
    public function color(int $number): Color
    {
        /** @var array<ManualColor> $colors */
        $colors = array_merge($this->detectedColors(), $this->customColors);

        return ($colors[$number - 1] ?? throw new MissingManualColor())->color;
    }

    public function colorsCount(): int
    {
        return count($this->detectedColors()) + count($this->customColors);
    }

    /**
     * @throws MissingManualColor
     */
    public function logoBackground(string $logoType, string $logoColor): string
    {
        $typeVariant = LogoTypeVariant::from($logoType);
        $colorVariant = LogoColorVariant::from($logoColor);

        $colorsMapping = $this->logo->variant($typeVariant)?->colorsMapping;
        $background = $colorsMapping[$colorVariant->value]->background ?? null;

        if ($background !== null) {
            return $background;
        }

        return DefaultLogoColors::background($typeVariant, $colorVariant, $this);
    }

    /**
     * @throws MissingManualColor
     * @return array<string, string>
     */
    public function logoColorMapping(string $logoType, string $logoColor): array
    {
        $typeVariant = LogoTypeVariant::from($logoType);
        $colorVariant = LogoColorVariant::from($logoColor);

        $mapping = DefaultLogoColors::mapping($typeVariant, $colorVariant, $this);

        $customMapping = $this->logo->variant($typeVariant)?->colorsMapping[$colorVariant->value]->colors ?? [];

        foreach ($customMapping as $from => $to) {
            $mapping[strtoupper($from)] = strtoupper($to);
        }

        return $mapping;
    }

    /**
     * @param array<ManualColor> $detectedColors
     * @param array<ManualColor> $customColors
     */
    public function editColors(
        array $detectedColors,
        array $customColors,
    ): void
    {
        $this->detectedColors = $detectedColors;
        $this->customColors = $customColors;
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

    /**
     * @return array<Color>
     */
    public function primaryColors(): array
    {
        /** @var array<ManualColor> $manualColors */
        $manualColors = array_merge($this->detectedColors, $this->customColors);
        $colors = [];

        foreach ($manualColors as $manualColor) {
            if ($manualColor->type === ManualColorType::Primary) {
                $colors[] = $manualColor->color;
            }
        }

        return $colors;
    }

    /**
     * @return array<Color>
     */
    public function secondaryColors(): array
    {
        /** @var array<ManualColor> $manualColors */
        $manualColors = array_merge($this->detectedColors, $this->customColors);
        $colors = [];

        foreach ($manualColors as $manualColor) {
            if ($manualColor->type === ManualColorType::Secondary) {
                $colors[] = $manualColor->color;
            }
        }

        return $colors;
    }

    private function changeName(string $name): void
    {
        $this->name = $name;
        $this->slug = Slugify::string($name);
    }

    /**
     * @param array<string, string> $mapping
     */
    public function updateColorMapping(
        LogoTypeVariant $typeVariant,
        LogoColorVariant $colorVariant,
        string $background,
        array $mapping,
    ): void
    {
        foreach ($mapping as $from => $to) {
            if (strtoupper($from) === strtoupper($to)) {
                unset($mapping[$from]);
            }
        }

        if ($background === DefaultLogoColors::background($typeVariant, $colorVariant, $this)) {
            $background = null;
        }

        $newLogo = clone $this->logo;
        $newLogo->variant($typeVariant)?->updateColorsMapping(
            $colorVariant,
            $background,
            $mapping,
        );

        $this->logo = $newLogo;
    }
}
