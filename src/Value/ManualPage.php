<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * The fixed pages a manual renders, and the texts they carry by default.
 *
 * The heading and the description used to be hardcoded in
 * `manual_preview.html.twig`. They live here instead so an admin can override
 * either of them per manual (`Manual::$pageTexts`) while the wording below
 * stays the fallback — every manual reads the same until someone changes it.
 *
 * The case VALUE is the persisted key: renaming one orphans the overrides
 * stored under the old name.
 *
 * A default description is DEVELOPER-authored HTML and rendered as such. An
 * admin override is plain text (paragraphs separated by a blank line) and is
 * always escaped — see `_manual_page_description.html.twig`.
 */
enum ManualPage: string
{
    case Intro = 'intro';
    case BasicLogos = 'basic_logos';
    case LogosWithClaim = 'logos_with_claim';
    case HorizontalBackgrounds = 'horizontal_backgrounds';
    case VerticalBackgrounds = 'vertical_backgrounds';
    case HorizontalMonochrome = 'horizontal_monochrome';
    case VerticalMonochrome = 'vertical_monochrome';
    case Symbol = 'symbol';
    case ProtectionZone = 'protection_zone';
    case MinimumDimensions = 'minimum_dimensions';
    case PrimaryColors = 'primary_colors';
    case SecondaryColors = 'secondary_colors';
    case PrimaryFont = 'primary_font';
    case SecondaryFont = 'secondary_font';

    public static function forFontType(ManualFontType $type): self
    {
        return match ($type) {
            ManualFontType::Primary => self::PrimaryFont,
            ManualFontType::Secondary => self::SecondaryFont,
        };
    }

    /**
     * Shown in the admin's edit modal so it is clear WHICH page is being
     * changed — the heading itself is what the admin may be replacing.
     */
    public function label(): string
    {
        return $this->defaultTitle();
    }

    public function defaultTitle(): string
    {
        return match ($this) {
            self::Intro => 'Úvod',
            self::BasicLogos => 'Základní logotypy',
            self::LogosWithClaim => 'Základní logotypy se sloganem',
            self::HorizontalBackgrounds => 'Horizontální logotyp - barevné pozadí',
            self::VerticalBackgrounds => 'Vertikální logotyp - barevné pozadí',
            self::HorizontalMonochrome => 'Horizontální logotyp - Černobílé použití',
            self::VerticalMonochrome => 'Vertikální logotyp - Černobílé použití',
            self::Symbol => 'Symbol',
            // Today's wording, kept verbatim so no live manual changes look
            // on deploy — it reads like a copy of the basic-logos heading, and
            // an admin can now correct it per manual, which is the point.
            self::ProtectionZone => 'Základní logotypy',
            self::MinimumDimensions => 'Minimální rozměry loga',
            self::PrimaryColors => 'Primární barevná paleta',
            self::SecondaryColors => 'Sekundární barevná paleta',
            self::PrimaryFont => 'Primární písmo',
            self::SecondaryFont => 'Sekundární písmo',
        };
    }

    /**
     * Developer-authored HTML — rendered raw, never user input.
     */
    public function defaultDescription(): string
    {
        return match ($this) {
            self::Intro => '',
            self::BasicLogos => 'Dle konkrétní aplikace je možné zvolit horizontální nebo vertikální variantu loga, případně samostatný symbol. Variantu loga je nutné zvolit tak, aby byla zajištěna maximální výraznost a čitelnost loga.',
            self::LogosWithClaim => 'Logotyp lze použít také ve variantě se sloganem (claimem), a to v horizontální nebo vertikální variantě, dle maximální výraznosti a čitelnosti logotypu.',
            self::HorizontalBackgrounds => 'Ukázka použití horizontálního logotypu na jiném než bílém pozadí. V každém případě je nutné aby logotyp na zvoleném pozadí měl správný kontrast (nezanikala žádná část s barevným podkladem).',
            self::VerticalBackgrounds => 'Ukázka použití vertikálního logotypu na jiném než bílém pozadí. V každém případě je nutné aby logotyp na zvoleném pozadí měl správný kontrast (nezanikala žádná jeho část s barevným podkladem).',
            self::HorizontalMonochrome, self::VerticalMonochrome => '<p>Černobílá varianta loga je určena pro použití v situacích, kde nelze aplikovat plnobarevnou verzi loga, například na tiskovinách, kde je omezená barevná paleta, nebo na propagačních materiálech, které vyžadují jednobarevné zpracování.</p>'
                . '<p><strong>Pozitivní verze (černé logo na bílém podkladu):</strong> Tato verze se používá na světlém podkladu, kde černá barva loga vynikne a zaručí maximální čitelnost.</p>'
                . '<p><strong>Negativní verze (bílé logo na černém podkladu):</strong> Tato verze je určena pro použití na tmavém podkladu, kde bílá barva loga kontrastuje s pozadím a zachovává viditelnost a čitelnost loga.</p>',
            self::Symbol => 'Symbol lze použít samostatně v případech, kdy je značka již dobře zavedená a rozpoznatelná, například na propagačních předmětech (vhodný pro použití v malých formátech, kde by plné logo mohlo být nečitelné), aplikacích, profilech na sociálních sítích nebo jako ikona aplikace.',
            self::ProtectionZone, self::MinimumDimensions => 'Ochranná zóna logotypu je prostor kolem loga, který musí zůstat volný, bez jakýchkoli grafických prvků, textů nebo jiných vizuálních elementů. Tato zóna je určena k zajištění toho, že logo bude vždy dobře čitelné a vizuálně výrazné, a že nebude rušeno okolními prvky',
            self::PrimaryColors => 'Barevná paleta značky je klíčovým prvkem vizuální identity, který zajišťuje konzistentní a rozpoznatelné vystupování značky napříč všemi médii a komunikačními kanály.',
            self::SecondaryColors => 'Sekundární barvy doplňují primární paletu a jsou určeny k použití ve vedlejších grafických prvcích, ikonách, tlačítkách a dalších podpůrných elementech. Jejich cílem je rozšířit barevný rozsah značky a umožnit flexibilnější aplikace, aniž by byla narušena vizuální soudržnost.',
            self::PrimaryFont => 'Je základním písmem vizuální identity značky a hraje klíčovou roli při vytváření konzistentního a profesionálního vzhledu napříč všemi komunikačními materiály. Pokud písmo obsahuje další řezy (např. Light, Medium, Semi-Bold), lze je použít pro specifické účely, vždy však v souladu s celkovou vizuální identitou značky.',
            self::SecondaryFont => 'Sekundární písmo slouží jako doplněk k primárnímu písmu a poskytuje značce větší flexibilitu při komunikaci. Obvykle je vybráno z písem, která jsou standardně dostupná na stolních počítačích. To je zvláště užitečné při jednoduché korespondenci, kdy materiály vytváří širší okruh lidí na různých zařízeních.',
        };
    }

    /**
     * The default rendered as the plain text an admin edits — it pre-fills the
     * textarea, so the admin tweaks the wording instead of retyping it.
     */
    public function defaultDescriptionAsPlainText(): string
    {
        $html = $this->defaultDescription();

        if ($html === '') {
            return '';
        }

        $text = (string) preg_replace('~</p>\s*<p[^>]*>~', "\n\n", $html);
        $text = strip_tags($text);

        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
