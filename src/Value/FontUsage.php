<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * Where a project's fonts are used — by templates and by calendar layouts —
 * and which font references they carry that no project face satisfies. Produced by
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
     * @param array<string, list<FontUsageCalendar>> $calendarsByFamily family → calendars whose layout references it
     * @param array<string, list<FontUsageCalendar>> $missingInCalendars the same as `$missing`, for calendar layouts
     */
    public function __construct(
        public array $sitesByFamily,
        public array $missing,
        public array $calendarsByFamily = [],
        public array $missingInCalendars = [],
    ) {
    }

    /**
     * @return list<FontUsageCalendar>
     */
    public function calendarsFor(string $family): array
    {
        return $this->calendarsByFamily[$family] ?? [];
    }

    /**
     * Distinct calendar names, in first-seen order, across any set of
     * families — every face of one font for the whole-font delete.
     *
     * @param list<string> $families
     * @return list<string>
     */
    public function calendarNamesFor(array $families): array
    {
        $names = [];

        foreach ($families as $family) {
            foreach ($this->calendarsFor($family) as $calendar) {
                $names[$calendar->calendarId] = $calendar->calendarName;
            }
        }

        return array_values($names);
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
        return $this->missing !== [] || $this->missingInCalendars !== [];
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
