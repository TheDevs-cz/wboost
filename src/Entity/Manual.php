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

    /** @var Collection<int, ManualFont>  */
    #[Immutable]
    #[OneToMany(targetEntity: ManualFont::class, mappedBy: 'manual', fetch: 'EXTRA_LAZY')]
    public Collection $fonts;

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
        $this->fonts = new ArrayCollection();
        $this->logo = Logo::withoutImages();
        $this->changeName($name);
    }

    public function edit(ManualType $type, string $name, null|string $introImage): void
    {
        $this->type = $type;
        $this->introImage = $introImage;
        $this->changeName($name);
    }

    public function editLogo(Logo $logo): void
    {
        $this->logo = $logo;

        /** @var array<string> $detectedColorsHexFromLogos */
        $detectedColorsHexFromLogos = [];
        $detectedColors = $this->detectedColors;

        foreach ($logo->getDetectedColors() as $detectedColor) {
            if (!in_array($detectedColor->hex, $detectedColorsHexFromLogos)) {
                $detectedColorsHexFromLogos[] = $detectedColor->hex;
            }
        }

        foreach ($detectedColors as $key => $detectedColor) {
            if (!in_array($detectedColor->color->hex, $detectedColorsHexFromLogos, true)) {
                unset($detectedColors[$key]);
            }
        }

        $this->detectedColors = array_values($detectedColors);
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

    public function logoBackground(string $logoType, string $logoColor): string
    {
        $typeVariant = LogoTypeVariant::from($logoType);
        $colorVariant = LogoColorVariant::from($logoColor);

        $colorsMapping = $this->logo->variant($typeVariant)?->colorsMapping;
        $background = $colorsMapping[$colorVariant->value]->background ?? null;

        if ($background === null) {
            $background = DefaultLogoColors::background($typeVariant, $colorVariant, $this);
        }

        return strtoupper($background);
    }

    /**
     * @return array<string, string>
     */
    public function logoColorMapping(string $logoType, string $logoColor): array
    {
        $typeVariant = LogoTypeVariant::from($logoType);
        $colorVariant = LogoColorVariant::from($logoColor);

        $finalMapping = [];

        $mapping = DefaultLogoColors::mapping($typeVariant, $colorVariant, $this);

        foreach ($mapping as $from => $to) {
            $finalMapping[strtoupper((string) $from)] = strtoupper($to);
        }

        $customMapping = $this->logo->variant($typeVariant)?->colorsMapping[$colorVariant->value]->colors ?? [];

        foreach ($customMapping as $from => $to) {
            $finalMapping[strtoupper((string) $from)] = strtoupper($to);
        }

        return $finalMapping;
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

    public function isBrandManual(): bool
    {
        return $this->type === ManualType::Brand;
    }

    public function pagesCount(): int
    {
        return $this->pages->count();
    }

    public function fontsCount(): int
    {
        return $this->fonts->count();
    }

    /** @return array<ManualColor> */
    public function detectedColors(): array
    {
        /** @var array<string> $detectedColorsHex */
        $detectedColorsHex = [];

        /** @var array<string> $detectedColorsHexFromLogos */
        $detectedColorsHexFromLogos = [];

        /** @return array<ManualColor> */
        $detectedColors = [];

        foreach ($this->detectedColors as $detectedColor) {
            $detectedColors[] = $detectedColor;
            $detectedColorsHex[] = $detectedColor->color->hex;
        }

        foreach ($this->logo->getDetectedColors() as $detectedColor) {
            if (!in_array($detectedColor->hex, $detectedColorsHex)) {
                $detectedColors[] = new ManualColor($detectedColor, null, null, null);
                $detectedColorsHex[] = $detectedColor->hex;
            }

            if (!in_array($detectedColor->hex, $detectedColorsHexFromLogos)) {
                $detectedColorsHexFromLogos[] = $detectedColor->hex;
            }
        }

        foreach ($detectedColors as $key => $detectedColor) {
            if (!in_array($detectedColor->color->hex, $detectedColorsHexFromLogos, true)) {
                unset($detectedColors[$key]);
            }
        }

        return array_values($detectedColors);
    }

    /**
     * @return array<ManualColor>
     */
    public function primaryColors(): array
    {
        /** @var array<ManualColor> $manualColors */
        $manualColors = array_merge($this->detectedColors, $this->customColors);
        $colors = [];

        foreach ($manualColors as $manualColor) {
            if ($manualColor->type === ManualColorType::Primary) {
                $colors[] = $manualColor;
            }
        }

        return $colors;
    }

    /**
     * @return array<ManualColor>
     */
    public function secondaryColors(): array
    {
        /** @var array<ManualColor> $manualColors */
        $manualColors = array_merge($this->detectedColors, $this->customColors);
        $colors = [];

        foreach ($manualColors as $manualColor) {
            if ($manualColor->type === ManualColorType::Secondary) {
                $colors[] = $manualColor;
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
