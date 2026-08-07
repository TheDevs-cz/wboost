<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Mcp;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use WBoost\Web\DependencyInjection\McpToolScopePass;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Services\OAuth2\DescribeConsentScopes;
use WBoost\Web\Value\McpCapability;

/**
 * Turns the MCP tools this build actually REGISTERS into the Czech "co s tím
 * jde dělat" list of the AI (MCP server) page.
 *
 * ## Why it is derived and not written out in Twig
 *
 * The page's whole promise is that it describes THIS server. A hand-kept list
 * in a template goes stale the moment a tool lands or is removed — silently,
 * because nothing renders differently — and a user then either hunts for a tool
 * their client never offers, or never learns about one it does. So the source
 * of truth is {@see McpToolScopePass::PARAMETER}, the same compile-time map the
 * runtime gate uses to decide what a token may call.
 *
 * Two failure directions, both handled rather than assumed away:
 *
 * - a tool this page has copy for is **not registered** → the row is skipped,
 *   so the page can never advertise a tool that does not exist;
 * - a tool is registered but has **no Czech copy** → it is listed as an
 *   explicit `known: false` row naming the raw tool, mirroring how
 *   {@see DescribeConsentScopes} renders a scope it cannot describe. Dropping
 *   it would hide a capability the user's client will happily offer them.
 *
 * `tests/Controller/Mcp/McpGuideControllerTest.php` asserts the described set
 * equals the registered set, so either direction fails the build.
 *
 * ## Permissions come from the consent screen's wording
 *
 * The Czech name of a permission is {@see DescribeConsentScopes}'s, not a
 * second translation of the same concept: the user reads one wording when they
 * approve a connection and must read the same wording here.
 */
readonly final class DescribeMcpCapabilities
{
    /**
     * Reading order — the jobs in the order someone learns them, which is also
     * the order the tools chain in: orient, find, inspect, picture, preview,
     * deliver, then the writing ones.
     *
     * @var list<string>
     */
    private const array ORDER = [
        'get_context',
        'find_templates',
        'describe_variant',
        'list_gallery',
        'render_variant',
        'export_variant',
        'upload_image',
        'preview_design',
        'set_design',
    ];

    /**
     * @param array<string, null|string> $toolScopes tool name => {@see McpScope} value, or null
     *                                               when the tool declares no scope
     */
    public function __construct(
        #[Autowire(param: McpToolScopePass::PARAMETER)]
        private array $toolScopes,
        private DescribeConsentScopes $describeConsentScopes,
    ) {
    }

    /**
     * @return list<McpCapability>
     */
    public function describe(): array
    {
        /** @var list<McpCapability> $capabilities */
        $capabilities = [];

        foreach (self::ORDER as $tool) {
            if (!array_key_exists($tool, $this->toolScopes)) {
                continue;
            }

            $copy = self::copy($tool);

            // Unreachable while ORDER and copy() are written together; skipping
            // rather than throwing keeps a half-edited pair from 500-ing a page
            // whose only job is to explain the feature.
            if ($copy === null) {
                continue;
            }

            $capabilities[] = $this->capability($tool, $copy[0], $copy[1], true);
        }

        foreach (array_keys($this->toolScopes) as $tool) {
            if (in_array($tool, self::ORDER, true)) {
                continue;
            }

            $copy = self::copy($tool);

            $capabilities[] = $copy !== null
                ? $this->capability($tool, $copy[0], $copy[1], true)
                : $this->capability(
                    $tool,
                    $tool,
                    'Tento nástroj je na serveru dostupný, ale tato stránka ho zatím neumí popsat. '
                    . 'Váš AI klient ho přesto nabídne — zeptejte se ho, co umí.',
                    false,
                );
        }

        return $capabilities;
    }

    private function capability(string $tool, string $title, string $description, bool $known): McpCapability
    {
        $scope = McpScope::tryFrom($this->toolScopes[$tool] ?? '');

        return new McpCapability(
            $tool,
            $title,
            $description,
            $scope,
            $scope !== null ? $this->scopeTitle($scope) : null,
            $known,
        );
    }

    /**
     * The Czech label of a permission, taken from the consent screen's own
     * description. A scope always describes itself first
     * ({@see McpScope::grants()} puts `$this` at the head), so the first line
     * is the one asked for; the fallback is the raw value, which cannot happen
     * for a real case and is not worth a throw.
     */
    private function scopeTitle(McpScope $scope): string
    {
        foreach ($this->describeConsentScopes->describeGranted([$scope->value]) as $described) {
            if ($described->value === $scope->value) {
                return $described->title;
            }
        }

        return $scope->value;
    }

    /**
     * The Czech copy for one tool: `[title, description]`, or null when this
     * page has none.
     *
     * Written as jobs rather than as tool signatures — "vyexportovat hotový
     * obrázek", not "export_variant(variantId, inputs, images)". Every factual
     * number here (1200 px, 300 DPI, 10 MB, ~3 MB) is the one stated in the
     * tool's own description, which is what the agent reads.
     *
     * @return null|array{string, string}
     */
    private static function copy(string $tool): null|array
    {
        return match ($tool) {
            'get_context' => [
                'Zjistit, co v účtu máte',
                'Asistent si vypíše projekty, ke kterým máte přístup, a u každého jeho značkové '
                . 'fonty, barvy a rozměry, ve kterých se navrhuje. Tímhle každá práce začíná.',
            ],
            'find_templates' => [
                'Najít šablonu',
                'Vyhledá v jednom projektu šablony podle názvu nebo kategorie a u každé vypíše '
                . 'její varianty — tedy jednotlivé rozměry, ve kterých design existuje.',
            ],
            'describe_variant' => [
                'Zjistit, co se do šablony vyplňuje',
                'Popíše jednu variantu do detailu: která textová pole se vyplňují, jaký mají '
                . 'limit délky, co je zamčené, co jde skrýt, kde jsou sloty na obrázky a které '
                . 'texty si mezi sebou dělí místo v kontejneru.',
            ],
            'list_gallery' => [
                'Projít galerii obrázků',
                'Vypíše složky a obrázky v galerii projektu i s jejich rozměry, aby šlo do slotu '
                . 'na fotku vybrat konkrétní obrázek, který už máte nahraný.',
            ],
            'render_variant' => [
                'Ukázat náhled vyplněné šablony',
                'Vyplní šablonu vaším textem a vrátí zmenšený náhled (nejvýše 1200 px na delší '
                . 'straně). Náhledy se nepočítají do statistik exportů, takže se dá donekonečna '
                . 'zkoušet, dokud text nesedí.',
            ],
            'export_variant' => [
                'Vyexportovat hotový obrázek',
                'Vyexportuje finální PNG v plné velikosti a bez ztráty kvality — u formátu A4 '
                . 've 300 DPI je to 2480 × 3508 px. Tohle je ten výsledek, který si odnesete; '
                . 'započítá se do statistik exportů projektu.',
            ],
            'upload_image' => [
                'Nahrát nový obrázek do galerie',
                'Přidá do galerie projektu obrázek, který asistentovi pošlete, a vrátí jeho id, '
                . 'aby ho šlo hned použít. Limit WBoostu je 10 MB, přes AI klienta se ale kvůli '
                . 'omezení přenosu vejde zhruba 3 MB. Mazat ani přejmenovávat obrázky odsud '
                . 'nejde — to zůstává na člověku ve WBoostu.',
            ],
            'preview_design' => [
                'Navrhnout design nanečisto',
                'Asistent může sám navrhnout rozvržení varianty a nechat si ho vykreslit, aniž '
                . 'by se cokoli uložilo. Vrátí obrázek a seznam toho, co je na návrhu špatně — '
                . 'třeba font, který v projektu není. Slouží k ladění před uložením.',
            ],
            'set_design' => [
                'Uložit navržený design do varianty',
                'Přepíše design varianty tím, co asistent navrhl, a rovnou uloží nový náhled. '
                . 'Ukládat může jen ten, kdo projekt vlastní (sdílený projekt stačí na čtení a '
                . 'export, ne na návrh), varianty ze synchronizovaných šablon se mění výhradně '
                . 've skupinovém editoru — a když by uložení zahodilo něco, co návrh neumí '
                . 'popsat (typicky pozadí nahrané mimo galerii), server ho nejdřív odmítne a '
                . 'vypíše, o co byste přišli.',
            ],
            default => null,
        };
    }
}
