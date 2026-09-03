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
use WBoost\Web\Doctrine\ManualPageTextsDoctrineType;
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
use WBoost\Web\Value\ManualPage;
use WBoost\Web\Value\ManualPageText;
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

    /**
     * Per-CARD logo width overrides, keyed by the slot id the manual template
     * gives each logo card (`<page>.<logoVariant>.<colorVariant|base>`). This
     * is the top of the width cascade — see `logoDisplayWidth()`.
     *
     * @var array<string, int>
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSON, options: ['default' => '{}'])]
    public array $logoSlotWidths = [];

    /**
     * Per-page heading / description overrides, keyed by `ManualPage` value.
     * A page absent from the map renders the wording the enum carries — that
     * is what makes every manual read the same until an admin edits one.
     *
     * @var array<string, ManualPageText>
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: ManualPageTextsDoctrineType::NAME, options: ['default' => '{}'])]
    public array $pageTexts = [];

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

    public function pageTitle(ManualPage $page): string
    {
        return $this->pageTexts[$page->value]->title ?? $page->defaultTitle();
    }

    /**
     * The admin's plain-text description, or null when the page still renders
     * the enum's HTML default. The caller has to tell the two apart: an
     * override is escaped, a default is developer-authored markup.
     */
    public function pageDescriptionOverride(ManualPage $page): null|string
    {
        return $this->pageTexts[$page->value]->description ?? null;
    }

    public function pageTextTitleOverride(ManualPage $page): null|string
    {
        return $this->pageTexts[$page->value]->title ?? null;
    }

    public function hasPageTextOverride(ManualPage $page): bool
    {
        return isset($this->pageTexts[$page->value]);
    }

    public function editPageText(ManualPage $page, null|string $title, null|string $description): void
    {
        $text = ManualPageText::fromInput($title, $description);
        $texts = $this->pageTexts;

        if ($text->isEmpty()) {
            unset($texts[$page->value]);
        } else {
            $texts[$page->value] = $text;
        }

        // Reassigned rather than mutated in place: Doctrine compares the
        // property by value for a JSON column, and an in-place write on the
        // same array instance is not always seen as a change.
        $this->pageTexts = $texts;
    }

    /**
     * How wide a single logo card renders its logo, as a percentage of the
     * card. Highest priority first:
     *
     *   1. the width set on THIS card (the pencil on the card itself),
     *   2. the width set for the logo VARIANT ("Šířka loga v manuálu"),
     *   3. null — no override, the stylesheet decides.
     *
     * Every logo card in the manual resolves its width through here, which is
     * what makes the variant-level setting reach all of them.
     */
    public function logoDisplayWidth(string $slot, string $logoVariant): null|int
    {
        $slotWidth = $this->logoSlotWidths[$slot] ?? null;

        if ($slotWidth !== null) {
            return $slotWidth;
        }

        return $this->logo->variant(LogoTypeVariant::from($logoVariant))?->displayWidth;
    }

    /**
     * The width THIS card carries on its own, ignoring the variant fallback —
     * what the card's own edit form is editing.
     */
    public function logoSlotWidth(string $slot): null|int
    {
        return $this->logoSlotWidths[$slot] ?? null;
    }

    public function logoVariantWidth(string $logoVariant): null|int
    {
        return $this->logo->variant(LogoTypeVariant::from($logoVariant))?->displayWidth;
    }

    /**
     * Null, 0 or an out-of-range value clears the card's own width, so the
     * card falls back to its variant — the same normalization
     * SvgImage::updateDisplayWidth does for the variant level.
     */
    public function editLogoSlotWidth(string $slot, null|int $displayWidth): void
    {
        $widths = $this->logoSlotWidths;

        if ($displayWidth === null || $displayWidth <= 0) {
            unset($widths[$slot]);
        } else {
            $widths[$slot] = min($displayWidth, 100);
        }

        // Reassigned, not mutated: Doctrine compares a JSON column by value.
        $this->logoSlotWidths = $widths;
    }

    /**
     * The logo variants the admin gave a page of their own — they render one
     * page each (all of their colour variants) and drop out of the pages that
     * otherwise mix two variants.
     *
     * @return list<LogoTypeVariant>
     */
    public function logoOwnPageVariants(): array
    {
        $variants = [];

        foreach (LogoTypeVariant::cases() as $variant) {
            $image = $this->logo->variant($variant);

            if ($image !== null && $image->ownPage === true) {
                $variants[] = $variant;
            }
        }

        return $variants;
    }

    /**
     * Whether a variant still belongs on the SHARED logo pages: it has to be
     * uploaded and not promoted to a page of its own. This is the guard every
     * shared page uses, so a promoted variant is never rendered twice.
     */
    public function logoOnSharedPage(string $logoVariant): bool
    {
        $image = $this->logo->variant(LogoTypeVariant::from($logoVariant));

        return $image !== null && $image->ownPage === false;
    }
}
