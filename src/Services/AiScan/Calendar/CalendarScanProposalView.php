<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan\Calendar;

use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use WBoost\Web\Entity\AiScan;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Value\AiScan\AiScanKind;
use WBoost\Web\Value\AiScan\AiScanStatus;

/**
 * The document the calendar review reads (ux.md C4; wizard step 2, the scan
 * panel) and MCP `result` answers — codes, numbers, boxes and the layout the
 * calendar would get **now**; no prose, nothing the scanner wrote as text.
 *
 * - `layout`, `preset` — what „Použít rozvržení“ would save into the calendar
 *   as it is stored at this moment ({@see CalendarScanProposal::plan()}); the
 *   browser draws it with the calendar's own renderer;
 * - `recognised[]` — one row per element: `found` | `approximate` | `absent`,
 *   and `kept` when the calendar keeps its current setting for it;
 * - `features[]` — switches the page shows and the calendar has off;
 * - `pictures[]` — the places for a picture: what is held for each, or that
 *   the calendar's own picture stays;
 * - `fonts[]`, `notExpressible[]` (code + source-pixel box), `notes[]`;
 * - `pageBox` / `photoBox` — where the page and its photo are in the
 *   uploaded picture (source pixels), for the browser's preview only;
 * - `applied` — after the accept: until when and whether „Vrátit původní“
 *   can be used, and whether it would overwrite later edits.
 */
final readonly class CalendarScanProposalView
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private GetFonts $getFonts,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return null|array<string, mixed>
     */
    public function view(AiScan $scan): null|array
    {
        $calendar = $scan->calendar;

        if ($scan->kind !== AiScanKind::Calendar || $calendar === null) {
            return null;
        }

        $applied = $this->applied($scan);

        if (!CalendarScanProposal::has($scan)) {
            return $applied === null ? null : ['kind' => 'calendar', 'calendarId' => $calendar->id->toString(), 'readOnly' => true, 'layout' => null, 'applied' => $applied];
        }

        $ready = $scan->status === AiScanStatus::Ready;
        $plan = CalendarScanProposal::plan($scan, $calendar);
        assert($plan !== null);

        $report = $scan->report ?? [];
        $proposal = $scan->proposal ?? [];
        $document = is_array($proposal['document'] ?? null) ? $proposal['document'] : [];
        $states = CalendarScanProposal::recognised($scan);
        $recognised = [];

        foreach (CalendarScanProposal::ELEMENTS as $element) {
            if (isset($states[$element])) {
                $recognised[] = ['element' => $element, 'state' => $states[$element], 'kept' => in_array($element, $plan['kept'], true)];
            }
        }

        $held = CalendarScanPictures::held($scan);
        $pictures = [];

        foreach (CalendarScanProposal::slots($scan) as $slot) {
            $picture = null;

            if (isset($held[$slot])) {
                $picture = [
                    'url' => $this->urlGenerator->generate('ai_scan_image', ['id' => $scan->id->toString(), 'which' => 'picture', 'slot' => $slot, 'v' => substr(sha1($held[$slot]['file'] . $held[$slot]['bytes'] . $held[$slot]['name']), 0, 8)]),
                    'name' => $held[$slot]['name'],
                    'width' => $held[$slot]['width'],
                    'height' => $held[$slot]['height'],
                ];
            }

            $pictures[] = [
                'slot' => $slot,
                'picture' => $ready ? $picture : null,
                // The calendar already has a picture there and keeps it.
                'current' => $picture === null && in_array($slot, $plan['currentPictures'], true),
            ];
        }

        $faces = 0;

        foreach ($this->getFonts->allForProject($calendar->project->id) as $font) {
            $faces += count($font->faces);
        }

        return [
            'kind' => 'calendar',
            'calendarId' => $calendar->id->toString(),
            'readOnly' => !$ready,
            'unfinished' => $scan->unfinished,
            'expiresAt' => $scan->expiresAt?->format(DATE_ATOM),
            'candidate' => $scan->keptCandidate,
            'source' => [
                'width' => is_int($scan->sourceMeta['workingWidth'] ?? null) ? $scan->sourceMeta['workingWidth'] : null,
                'height' => is_int($scan->sourceMeta['workingHeight'] ?? null) ? $scan->sourceMeta['workingHeight'] : null,
            ],
            'pageBox' => self::box($report['pageBox'] ?? null),
            'photoBox' => self::box($report['photoBox'] ?? null),
            'page' => is_string($document['page'] ?? null) ? $document['page'] : CalendarCandidate::SAMPLE_PAGE,
            'view' => $document['view'] ?? null,
            'hasLayout' => $calendar->layout !== null,
            'preset' => $plan['preset'],
            'layout' => $plan['layout'],
            'recognised' => $recognised,
            'features' => array_map(static fn (string $key): array => ['key' => $key], CalendarScanProposal::featuresOffered($scan, $ready ? $calendar : null)),
            'pictures' => $pictures,
            'fonts' => self::fonts($report['fonts'] ?? null),
            'projectFaces' => $faces,
            'notExpressible' => self::coded($report['notExpressible'] ?? null, CalendarScanProposal::NOT_EXPRESSIBLE, true),
            'notes' => self::coded($report['notes'] ?? null, CalendarScanProposal::NOTES, false),
            'approximate' => $scan->approximate,
            'rating' => $scan->rating,
            'applied' => $applied,
        ];
    }

    /**
     * @return null|array{restoreUntil: null|string, restorable: bool, restored: bool, changed: bool, features: list<string>}
     */
    public function applied(AiScan $scan): null|array
    {
        $restore = $scan->restore;
        $calendar = $scan->calendar;

        if ($scan->status !== AiScanStatus::Accepted || $scan->kind !== AiScanKind::Calendar) {
            return null;
        }

        $restored = is_array($restore) && isset($restore['restoredAt']);
        $alive = is_array($restore) && $calendar !== null && $scan->expiresAt !== null && $scan->expiresAt > $this->clock->now();
        /** @var list<string> $features */
        $features = is_array($restore) && is_array($restore['features'] ?? null) ? array_values(array_filter($restore['features'], is_string(...))) : [];

        return [
            'restoreUntil' => $alive ? $scan->expiresAt->format(DATE_ATOM) : null,
            'restorable' => $alive && !$restored,
            'restored' => $restored,
            // Edits since the accept: „Vrátit původní“ asks before it overwrites them.
            'changed' => $alive && !$restored
                && CalendarScanProposal::hash($calendar->layout, $calendar->features) !== ($restore['appliedHash'] ?? null),
            'features' => $features,
        ];
    }

    /**
     * @return null|list<int>
     */
    private static function box(mixed $box): null|array
    {
        if (!is_array($box) || !array_is_list($box) || count($box) !== 4) {
            return null;
        }

        $out = [];

        foreach ($box as $value) {
            if (!is_int($value) && !is_float($value)) {
                return null;
            }

            $out[] = (int) round($value);
        }

        return $out;
    }

    /**
     * @param list<string> $codes
     * @return list<array<string, mixed>>
     */
    private static function coded(mixed $items, array $codes, bool $withBox): array
    {
        $out = [];

        foreach (is_array($items) ? $items : [] as $item) {
            $code = is_array($item) ? ($item['code'] ?? null) : null;

            if (!is_string($code) || !in_array($code, $codes, true)) {
                continue;
            }

            $row = ['code' => $code];

            if ($withBox) {
                $row['box'] = self::box($item['box'] ?? null);
            }

            foreach (['w', 'h'] as $number) {
                if (!$withBox && (is_int($item[$number] ?? null) || is_float($item[$number] ?? null))) {
                    $row[$number] = (int) round($item[$number]);
                }
            }

            $out[] = $row;
        }

        return array_slice($out, 0, 20);
    }

    /**
     * @return list<array{group: string, pattern: string, face: null|string, closeness: null|float}>
     */
    private static function fonts(mixed $items): array
    {
        $out = [];

        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_array($item) || !is_string($item['group'] ?? null) || !is_string($item['pattern'] ?? null)) {
                continue;
            }

            $out[] = [
                'group' => $item['group'],
                'pattern' => $item['pattern'],
                'face' => is_string($item['face'] ?? null) ? $item['face'] : null,
                'closeness' => is_int($item['closeness'] ?? null) || is_float($item['closeness'] ?? null) ? round((float) $item['closeness'], 3) : null,
            ];
        }

        return array_slice($out, 0, 30);
    }
}
