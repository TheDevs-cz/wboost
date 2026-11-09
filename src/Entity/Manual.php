<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OrderBy;
use WBoost\Web\Doctrine\LogoDoctrineType;
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
use WBoost\Web\Value\LogoScale;
use WBoost\Web\Value\LogoTypeVariant;
use WBoost\Web\Value\ManualColor;
use WBoost\Web\Value\ManualColorType;
use WBoost\Web\Value\ManualContent\ManualContentPageRole;
use WBoost\Web\Value\ManualPage;
use WBoost\Web\Value\ManualPageText;
use WBoost\Web\Value\ManualType;
use WBoost\Web\Value\RgbaColor;

#[Entity(repositoryClass: ManualDoctrineRepository::class)]
class Manual
{
    /**
     * The manual's colours (F7 — the only colour storage since P5a retired
     * the `detected_colors` / `custom_colors` JSONB columns): links to project
     * palette colours in the manual's colour order — logo segment first, then
     * the added colours. EAGER (batch-loaded, palette colour joined): every
     * listing that shows several manuals reads their colours.
     *
     * @var Collection<int, ManualPaletteColor>
     */
    #[OneToMany(targetEntity: ManualPaletteColor::class, mappedBy: 'manual', fetch: 'EAGER')]
    #[OrderBy(['position' => 'ASC'])]
    private Collection $paletteLinks;

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

    /**
     * Intro + custom content pages; only read for counting (the pages
     * themselves are loaded through `ManualContentPageRepository`).
     *
     * @var Collection<int, ManualContentPage>
     */
    #[Immutable]
    #[OneToMany(targetEntity: ManualContentPage::class, mappedBy: 'manual', fetch: 'EXTRA_LAZY')]
    public Collection $contentPages;

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
    ) {
        $this->pages = new ArrayCollection();
        $this->contentPages = new ArrayCollection();
        $this->fonts = new ArrayCollection();
        $this->paletteLinks = new ArrayCollection();
        $this->logo = Logo::withoutImages();
        $this->changeName($name);
    }

    public function edit(ManualType $type, string $name): void
    {
        $this->type = $type;
        $this->changeName($name);
    }

    /**
     * Only the logo — its colours reach the palette through
     * {@see \WBoost\Web\Services\Palette\ManualPaletteWriter::syncLogoColors()} (D3),
     * which the caller runs after flushing.
     */
    public function editLogo(Logo $logo): void
    {
        $this->logo = $logo;
    }

    /**
     * Positional: the logo segment, then the added colours — what logo
     * recolouring indexes (`DefaultLogoColors` uses colours 1–3 as FROM keys).
     *
     * @throws MissingManualColor
     */
    public function color(int $number): Color
    {
        /** @var array<ManualColor> $colors */
        $colors = array_merge($this->detectedColors(), $this->customColors());

        return ($colors[$number - 1] ?? throw new MissingManualColor())->color;
    }

    public function colorsCount(): int
    {
        return count($this->sortedPaletteLinks());
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
     * The recolouring a logo is RENDERED with: FROM literal → target, where a
     * translucent target ("Krytí" < 100 %) is `rgba(r,g,b,a)` and an opaque
     * one stays the plain HEX (byte-identical to before opacity existed).
     * {@see \WBoost\Web\Services\SvgColorsMapper} understands both.
     *
     * @return array<string, string>
     */
    public function logoColorMapping(string $logoType, string $logoColor): array
    {
        $opacities = $this->logoColorOpacities($logoType, $logoColor);
        $mapping = [];

        foreach ($this->logoColorTargets($logoType, $logoColor) as $from => $to) {
            $from = (string) $from;
            $opacity = $opacities[$from] ?? 100;

            $mapping[$from] = ($opacity < 100 && Color::isValidHex($to))
                ? RgbaColor::fromHex($to, $opacity)->css()
                : $to;
        }

        return $mapping;
    }

    /**
     * The mapping as HEX only (default mapping overlaid by the custom one) —
     * what the "Mapování barev loga" form edits.
     *
     * @return array<string, string>
     */
    public function logoColorTargets(string $logoType, string $logoColor): array
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
     * "Krytí" in percent of the translucent targets only (absent = 100 %).
     *
     * @return array<string, int>
     */
    public function logoColorOpacities(string $logoType, string $logoColor): array
    {
        $typeVariant = LogoTypeVariant::from($logoType);
        $colorVariant = LogoColorVariant::from($logoColor);

        return $this->logo->variant($typeVariant)?->colorsMapping[$colorVariant->value]->opacities ?? [];
    }

    /**
     * Keeps the inverse side in step for links created through the ORM
     * (fixtures, tests) — {@see ManualPaletteColor::__construct()} calls it.
     * The runtime writer works over DBAL and refreshes the manual instead.
     *
     * @internal
     */
    public function addPaletteLink(ManualPaletteColor $link): void
    {
        if (!$this->paletteLinks->contains($link)) {
            $this->paletteLinks->add($link);
        }
    }

    /**
     * @return list<ManualPaletteColor> manual colour order
     */
    public function paletteLinks(): array
    {
        return $this->sortedPaletteLinks();
    }

    public function isBrandManual(): bool
    {
        return $this->type === ManualType::Brand;
    }

    /**
     * Pages of the sortable "Stránky" list: mockup pages + custom content
     * pages (the intro has its own tab).
     */
    public function pagesCount(): int
    {
        $customPages = $this->contentPages->matching(
            Criteria::create()->where(Criteria::expr()->eq('role', ManualContentPageRole::Custom)),
        );

        return $this->pages->count() + $customPages->count();
    }

    public function fontsCount(): int
    {
        return $this->fonts->count();
    }

    /**
     * "Z logotypu": the logo links in manual order, each with its LITERAL logo
     * colour (recolouring `str_ireplace`s it on the SVG) and the palette
     * colour's role + print data.
     *
     * @return list<ManualColor>
     */
    public function detectedColors(): array
    {
        $colors = [];

        foreach ($this->sortedPaletteLinks() as $link) {
            if ($link->isFromLogo()) {
                $colors[] = $link->manualColor();
            }
        }

        return $colors;
    }

    /**
     * The added colours, in manual order — the palette colour's HEX spelling
     * (§7), role and print data.
     *
     * @return list<ManualColor>
     */
    public function customColors(): array
    {
        $colors = [];

        foreach ($this->sortedPaletteLinks() as $link) {
            if (!$link->isFromLogo()) {
                $colors[] = $link->manualColor();
            }
        }

        return $colors;
    }

    /**
     * The customer manual's "primary colours" page: the manual's colours whose
     * PROJECT palette colour is primary (D2), in manual order.
     *
     * @return list<ManualColor>
     */
    public function primaryColors(): array
    {
        return $this->colorsOfRole(ManualColorType::Primary);
    }

    /**
     * @return list<ManualColor>
     */
    public function secondaryColors(): array
    {
        return $this->colorsOfRole(ManualColorType::Secondary);
    }

    /**
     * @return list<ManualColor>
     */
    private function colorsOfRole(ManualColorType $role): array
    {
        $colors = [];

        foreach ([...$this->detectedColors(), ...$this->customColors()] as $color) {
            if ($color->type === $role) {
                $colors[] = $color;
            }
        }

        return $colors;
    }

    /**
     * @return list<ManualPaletteColor>
     */
    private function sortedPaletteLinks(): array
    {
        $links = $this->paletteLinks->toArray();
        usort($links, static fn (ManualPaletteColor $a, ManualPaletteColor $b): int => $a->position <=> $b->position);

        return $links;
    }

    private function changeName(string $name): void
    {
        $this->name = $name;
        $this->slug = Slugify::string($name);
    }

    /**
     * @param array<string, string> $mapping
     * @param array<string, int> $opacities percent per FROM colour; 100 / absent = opaque
     */
    public function updateColorMapping(
        LogoTypeVariant $typeVariant,
        LogoColorVariant $colorVariant,
        string $background,
        array $mapping,
        array $opacities = [],
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
            $opacities,
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
    /**
     * The card's `--logo-scale` (see {@see LogoScale}), or null when it
     * renders at the default size.
     */
    public function logoDisplayScale(string $slot, string $logoVariant): null|string
    {
        return LogoScale::cssFactor($this->logoDisplayWidth($slot, $logoVariant));
    }

    public function logoSlotWidth(string $slot): null|int
    {
        return $this->logoSlotWidths[$slot] ?? null;
    }

    public function logoVariantWidth(string $logoVariant): null|int
    {
        return $this->logo->variant(LogoTypeVariant::from($logoVariant))?->displayWidth;
    }

    /**
     * Null or 0 clears the card's own width, so the card falls back to its
     * variant; anything else is clamped into {@see LogoScale} MIN–MAX. Unlike
     * the variant level, 100 IS stored here: it puts this one card back to
     * the default size even when its variant is scaled.
     */
    public function editLogoSlotWidth(string $slot, null|int $displayWidth): void
    {
        $widths = $this->logoSlotWidths;
        $displayWidth = LogoScale::normalize($displayWidth);

        if ($displayWidth === null) {
            unset($widths[$slot]);
        } else {
            $widths[$slot] = $displayWidth;
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
