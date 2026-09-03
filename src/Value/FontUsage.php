<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * Where a project's fonts are used, and which font references its templates
 * carry that no project face satisfies. Produced by
 * {@see \WBoost\Web\Query\GetFontUsage}; consumed by the fonts page (usage
 * chips, the delete confirmations, the "Písma mimo projekt" card) and the
 * project dashboard badge.
 *
 * Keys are the canvas' own vocabulary: the exact `"<Font> (<Face>)"` family
 * string, or a bare font name where a canvas carries one.
 */
readonly final class FontUsage
{
    /**
     * @param array<string, list<FontUsageSite>> $sitesByFamily family → variants referencing it (deduped per variant)
     * @param array<string, list<FontUsageSite>> $missing referenced families no project face (or font) satisfies
     */
    public function __construct(
        public array $sitesByFamily,
        public array $missing,
    ) {
    }

    /**
     * @return list<FontUsageSite>
     */
    public function sitesFor(string $family): array
    {
        return $this->sitesByFamily[$family] ?? [];
    }

    /**
     * Distinct templates referencing a family (a template with three
     * dimensions counts once — that is how the designer thinks of it).
     */
    public function templatesCountFor(string $family): int
    {
        return count(self::templateNames($this->sitesFor($family)));
    }

    /**
     * Distinct template names, in first-seen order, across any set of
     * families — e.g. every face of one font for the whole-font delete.
     *
     * @param list<string> $families
     * @return list<string>
     */
    public function templateNamesFor(array $families): array
    {
        $sites = [];
        foreach ($families as $family) {
            foreach ($this->sitesFor($family) as $site) {
                $sites[] = $site;
            }
        }

        return self::templateNames($sites);
    }

    public function hasMissing(): bool
    {
        return $this->missing !== [];
    }

    /**
     * @param list<FontUsageSite> $sites
     * @return list<string>
     */
    private static function templateNames(array $sites): array
    {
        $names = [];
        $seen = [];
        foreach ($sites as $site) {
            if (isset($seen[$site->templateId])) {
                continue;
            }
            $seen[$site->templateId] = true;
            $names[] = $site->templateName;
        }

        return $names;
    }
}
