<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\Font;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Value\FontUsage;
use WBoost\Web\Value\FontUsageCalendar;
use WBoost\Web\Value\FontUsageSite;
use WBoost\Web\Value\RichText;

/**
 * Which templates and calendars reference which font, scanned from their own
 * documents: a calendar layout's text styles (every `family` under
 * `layout.styles`, the rule of the calendar JavaScript's `usedFamilies()`),
 * and the variants' documents — the canvas JSON (every textbox's `fontFamily`, including the
 * per-character styles a rich stand-in may carry), the inputs' font
 * allowlists and the rich sample values' runs. Nothing is stored: a font is
 * referenced by its family STRING, so the scan is the only truthful source,
 * and it runs on the admin fonts page and the dashboard only.
 *
 * "Missing" = a referenced family that neither an uploaded face
 * (`"<Font> (<Face>)"`) nor a bare font name of the project satisfies —
 * a face the designer deleted, or the editor's "Arial" default in a project
 * that had no fonts yet. Such a text renders in a fallback font.
 */
readonly final class GetFontUsage
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GetFonts $getFonts,
    ) {
    }

    public function forProject(UuidInterface $projectId): FontUsage
    {
        /** @var list<TemplateVariant> $variants */
        $variants = $this->entityManager->createQueryBuilder()
            ->from(TemplateVariant::class, 'v')
            ->select('v', 't')
            ->join('v.template', 't')
            ->where('t.project = :projectId')
            ->setParameter('projectId', $projectId->toString())
            ->orderBy('t.name')
            ->getQuery()
            ->getResult();

        return self::compute($this->getFonts->allForProject($projectId), $variants, $this->calendarLayouts($projectId));
    }

    /**
     * The project's calendars that have a layout, with the families it names.
     *
     * @return list<array{FontUsageCalendar, list<string>}>
     */
    private function calendarLayouts(UuidInterface $projectId): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT id, name, layout FROM calendar WHERE project_id = :projectId AND layout IS NOT NULL ORDER BY name, id',
            ['projectId' => $projectId->toString()],
        );

        $calendars = [];

        foreach ($rows as $row) {
            if (!is_string($row['id']) || !is_string($row['name']) || !is_string($row['layout'])) {
                continue;
            }

            $layout = json_decode($row['layout'], true);
            $families = [];

            if (is_array($layout)) {
                self::collectCalendarFamilies($layout['styles'] ?? null, $families);
            }

            $calendars[] = [new FontUsageCalendar($row['id'], $row['name']), array_keys($families)];
        }

        return $calendars;
    }

    /**
     * Every string under a `family` key, at any depth.
     *
     * @param array<string, true> $families
     */
    private static function collectCalendarFamilies(mixed $node, array &$families): void
    {
        if (!is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            if ($key === 'family') {
                if (is_string($value) && $value !== '') {
                    $families[$value] = true;
                }

                continue;
            }

            self::collectCalendarFamilies($value, $families);
        }
    }

    /**
     * Pure core.
     *
     * @param array<Font> $projectFonts
     * @param list<TemplateVariant> $variants
     * @param list<array{FontUsageCalendar, list<string>}> $calendars each calendar with the families its layout names
     */
    public static function compute(array $projectFonts, array $variants, array $calendars = []): FontUsage
    {
        $known = [];
        foreach ($projectFonts as $font) {
            $known[$font->name] = true;
            foreach ($font->faces as $face) {
                $known[$font->faceFamily($face)] = true;
            }
        }

        /** @var array<string, list<FontUsageSite>> $sitesByFamily */
        $sitesByFamily = [];

        foreach ($variants as $variant) {
            $site = new FontUsageSite(
                $variant->id->toString(),
                $variant->template->id->toString(),
                $variant->template->name,
                $variant->dimension->label(),
                $variant->group?->id->toString(),
            );

            foreach (self::referencedFamilies($variant) as $family) {
                $sitesByFamily[$family][] = $site;
            }
        }

        ksort($sitesByFamily);

        $missing = [];
        foreach ($sitesByFamily as $family => $sites) {
            if (!isset($known[$family])) {
                $missing[$family] = $sites;
            }
        }

        /** @var array<string, list<FontUsageCalendar>> $calendarsByFamily */
        $calendarsByFamily = [];

        foreach ($calendars as [$calendar, $families]) {
            foreach ($families as $family) {
                $calendarsByFamily[$family][] = $calendar;
            }
        }

        ksort($calendarsByFamily);

        $missingInCalendars = [];
        foreach ($calendarsByFamily as $family => $using) {
            if (!isset($known[$family])) {
                $missingInCalendars[$family] = $using;
            }
        }

        return new FontUsage($sitesByFamily, $missing, $calendarsByFamily, $missingInCalendars);
    }

    /**
     * Every family string one variant references, deduped.
     *
     * @return list<string>
     */
    private static function referencedFamilies(TemplateVariant $variant): array
    {
        $families = [];

        $canvas = json_decode($variant->canvas, true);
        if (is_array($canvas)) {
            foreach (is_array($canvas['objects'] ?? null) ? $canvas['objects'] : [] as $object) {
                if (!is_array($object)) {
                    continue;
                }
                $type = $object['type'] ?? null;
                if (!is_string($type) || strtolower($type) !== 'textbox') {
                    continue;
                }
                self::collectFontFamilies($object, $families);
            }
        }

        foreach ($variant->inputs as $input) {
            foreach ($input->allowedFonts as $family) {
                $families[$family] = true;
            }

            if ($input->sampleValue !== null) {
                $envelope = RichText::tryExtractEnvelope($input->sampleValue);
                foreach ($envelope['runs'] ?? [] as $run) {
                    if (is_array($run) && is_string($run['fontFamily'] ?? null) && $run['fontFamily'] !== '') {
                        $families[$run['fontFamily']] = true;
                    }
                }
            }
        }

        return array_keys($families);
    }

    /**
     * A textbox's `fontFamily` plus any per-character override buried in its
     * `styles` grid (line → char → {fontFamily}).
     *
     * @param array<array-key, mixed> $object
     * @param array<string, true> $families
     */
    private static function collectFontFamilies(array $object, array &$families): void
    {
        $family = $object['fontFamily'] ?? null;
        if (is_string($family) && $family !== '') {
            $families[$family] = true;
        }

        $styles = $object['styles'] ?? null;
        if (!is_array($styles)) {
            return;
        }

        foreach ($styles as $line) {
            if (!is_array($line)) {
                continue;
            }
            foreach ($line as $char) {
                $charFamily = is_array($char) ? ($char['fontFamily'] ?? null) : null;
                if (is_string($charFamily) && $charFamily !== '') {
                    $families[$charFamily] = true;
                }
            }
        }
    }
}
