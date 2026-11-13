<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan\Calendar;

use WBoost\Web\Entity\Calendar;
use WBoost\Web\Exceptions\CalendarModelRefused;
use WBoost\Web\Exceptions\CalendarModelUnavailable;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Services\Calendar\CalendarDocument;
use WBoost\Web\Services\Calendar\CalendarModelBridge;
use WBoost\Web\Value\Calendar\CalendarType;

/**
 * One candidate of a calendar scan (docs/architecture/ai-scan.md § "Calendar
 * scans"): the scanner's model commands run on the calendar's document **in
 * memory** — the calendar's own JavaScript, through
 * {@see CalendarModelBridge} — and one sample week page of the result drawn
 * by {@see \WBoost\Web\Services\Calendar\CalendarPageRenderer::renderDocument()}. Nothing is saved: no
 * revision, no history line, no asset.
 *
 * The commands a scan may send are a closed subset of the model's:
 *
 * - `{op: "preset", preset}` — always a RESET to the preset's default layout
 *   (the candidate never inherits the calendar's current styling);
 * - `{op: "configure", features: {<key>: true}}` — switches ON only;
 * - `{op: "layout", boxes?, order?, styles?}` — the validated patch.
 *
 * A command the model refuses is an answer (`blocked`, with the model's
 * message as the issue), exactly like a design the template preflight
 * rejects.
 */
final readonly class CalendarCandidate
{
    /** The week page a candidate shows: the second ISO week never holds a public holiday. */
    public const string SAMPLE_PAGE = 'w02';

    private const int MAX_COMMANDS = 8;

    public function __construct(
        private CalendarModelBridge $model,
        private CalendarCandidatePictureInterface $picture,
        private CalendarDocument $document,
    ) {
    }

    /**
     * @return array{
     *     issues: list<array<string, mixed>>, blocked: bool, bytes: null|string, width: null|int, height: null|int,
     *     design: null|array<string, mixed>, answer: array<string, mixed>,
     * }
     *
     * @throws TemplateRenderUnavailable the renderer is busy or down
     * @throws CalendarModelUnavailable node could not run
     */
    public function render(Calendar $calendar, mixed $commands): array
    {
        if ($calendar->type !== CalendarType::Week) {
            return self::blocked('unsupported_type', 'Only 7-day ("week") calendars can take a scanned layout.');
        }

        $commands = self::commands($commands, $calendar);

        if (is_string($commands)) {
            return self::blocked('invalid_command', $commands);
        }

        try {
            $model = $this->model->run($calendar, $commands, pages: true);
        } catch (CalendarModelRefused $refused) {
            return self::blocked($refused->reason, $refused->getMessage());
        }

        $layout = $model->design->layout ?? null;

        if (!$layout instanceof \stdClass) {
            return self::blocked('no_layout', 'The commands left the calendar without a layout — start with {"op": "preset", "preset": "photoLeft" | "photoRight"}.');
        }

        $page = self::samplePage($model->pages ?? []);

        if ($page === null) {
            return self::blocked('no_week_page', 'The calendar has no week page to draw.');
        }

        $document = $this->document->calendar($calendar);
        $features = $model->design->features ?? null;
        $preset = is_string($model->design->preset ?? null) ? $model->design->preset : 'photoLeft';
        $document['layout'] = $layout;
        $document['preset'] = $preset;
        $document['features'] = $features instanceof \stdClass ? $features : (object) $calendar->features;
        // Never the calendar's own content: this picture goes to the scanner
        // and on to the AI provider (CalendarCandidateContent).
        $document['content'] = self::withSampleEvents(CalendarCandidateContent::neutral(), $document['features'], $page);

        try {
            $render = $this->picture->picture($calendar, $document, $page['key']);
        } catch (CalendarModelRefused $refused) {
            return self::blocked($refused->reason, $refused->getMessage());
        }

        $on = [];

        foreach ($commands as $command) {
            if ($command['op'] === 'configure' && is_array($command['features'] ?? null)) {
                foreach (array_keys($command['features']) as $key) {
                    $on[(string) $key] = true;
                }
            }
        }

        $layoutJson = CalendarDocument::encode(['layout' => $layout]);
        $issues = array_map(
            static fn (string $note): array => ['severity' => 'info', 'stage' => 'model', 'code' => 'note', 'path' => '', 'message' => mb_substr($note, 0, 300)],
            array_slice($model->notes, 0, 40),
        );

        return [
            'issues' => $issues,
            'blocked' => false,
            'bytes' => $render->bytes,
            'width' => $render->width,
            'height' => $render->height,
            // What is stored with the candidate — the proposal, should it be kept.
            // The layout travels as a JSON STRING: it holds maps that are empty or
            // keyed by digits, which a PHP array would turn into lists.
            'design' => [
                'kind' => 'calendar',
                'preset' => $preset,
                'featuresOn' => array_keys($on),
                'layoutJson' => substr($layoutJson, strlen('{"layout":'), -1),
                'page' => $page['key'],
                'view' => $render->view,
            ],
            'answer' => [
                'view' => $render->view,
                'page' => ['key' => $page['key'], 'from' => $page['from'] ?? null, 'to' => $page['to'] ?? null],
                'preset' => $preset,
                'layout' => $layout,
                'features' => $document['features'],
            ],
        ];
    }

    /**
     * What a build may know about the calendar it scans for: type, year,
     * page, bleed, the switches as they are, the sample page and the default
     * layout of a preset — the whole style tree with every key the patch
     * path accepts.
     *
     * @return array<string, mixed>
     *
     * @throws CalendarModelUnavailable
     */
    public function context(Calendar $calendar): array
    {
        try {
            $model = $this->model->run($calendar, [['op' => 'preset', 'preset' => 'photoLeft', 'reset' => true]], pages: true);
        } catch (CalendarModelRefused) {
            // A 14-day calendar: there is nothing a scan could build on.
            return ['type' => $calendar->type->value, 'supported' => false];
        }

        $design = $model->design;
        $page = self::samplePage($model->pages ?? []);
        $print = $design->print ?? null;

        return [
            'supported' => $calendar->type === CalendarType::Week,
            'type' => $calendar->type->value,
            'year' => $calendar->year,
            'page' => $design->page ?? new \stdClass(),
            'bleedMm' => $print instanceof \stdClass && ($print->bleed ?? false) === true && is_numeric($print->bleedMm ?? null) ? (float) $print->bleedMm : 0.0,
            'features' => $design->features ?? new \stdClass(),
            'hasLayout' => $calendar->layout !== null,
            'presets' => $calendar->type->presets(),
            'samplePage' => $page === null ? null : ['key' => $page['key'], 'from' => $page['from'] ?? null, 'to' => $page['to'] ?? null, 'title' => $page['title']],
            'defaultLayout' => ['preset' => 'photoLeft', 'layout' => $design->layout ?? null],
        ];
    }

    /**
     * @param list<array{index: int, key: string, kind: string, title: string, subtitle: string, from?: string, to?: string}> $pages
     * @return null|array{index: int, key: string, kind: string, title: string, subtitle: string, from?: string, to?: string}
     */
    private static function samplePage(array $pages): null|array
    {
        $first = null;

        foreach ($pages as $page) {
            if ($page['kind'] !== 'week') {
                continue;
            }

            if ($page['key'] === self::SAMPLE_PAGE) {
                return $page;
            }

            $first ??= $page;
        }

        return $first;
    }

    /**
     * A candidate that shows events needs events to show: when the switch is
     * on and the sample week holds none, two sample events are put on the
     * page — in THIS in-memory document only — so the scan can see where its
     * event texts land (a calendar being designed rarely has events in the
     * second week of January). Nothing of it is ever saved.
     *
     * @param array{index: int, key: string, kind: string, title: string, subtitle: string, from?: string, to?: string} $page
     */
    private static function withSampleEvents(mixed $content, \stdClass $features, array $page): mixed
    {
        $from = $page['from'] ?? null;
        $to = $page['to'] ?? null;

        if (!$content instanceof \stdClass || ($features->events ?? false) !== true || !is_string($from) || !is_string($to)) {
            return $content;
        }

        $events = $content->events ?? null;

        foreach ($events instanceof \stdClass ? get_object_vars($events) : [] as $event) {
            $date = $event instanceof \stdClass ? ($event->date ?? null) : (is_array($event) ? ($event['date'] ?? null) : null);

            if (is_string($date) && $date >= $from && $date <= $to) {
                return $content;
            }
        }

        try {
            $monday = new \DateTimeImmutable($from);
        } catch (\Exception) {
            return $content;
        }

        $content = clone $content;
        $all = $events instanceof \stdClass ? get_object_vars($events) : [];
        $all['scanSampleEvent1'] = (object) ['date' => $monday->modify('+2 days')->format('Y-m-d'), 'name' => 'Ukázková akce', 'club' => null, 'icon' => null];
        $all['scanSampleEvent2'] = (object) ['date' => $monday->modify('+5 days')->format('Y-m-d'), 'name' => 'Ukázková akce s delším názvem', 'club' => null, 'icon' => null];
        $content->events = (object) $all;

        return $content;
    }

    /**
     * The closed command list — or the sentence saying what is wrong with it.
     *
     * @return list<array<string, mixed>>|string
     */
    private static function commands(mixed $raw, Calendar $calendar): array|string
    {
        if (!is_array($raw) || !array_is_list($raw) || $raw === [] || count($raw) > self::MAX_COMMANDS) {
            return sprintf('"commands" must be a list of 1 to %d model commands.', self::MAX_COMMANDS);
        }

        /** @var list<array<string, mixed>> $commands */
        $commands = [];
        $hasPreset = false;

        foreach ($raw as $index => $command) {
            if (!is_array($command) || !is_string($command['op'] ?? null)) {
                return sprintf('commands[%d] must be an object with "op".', $index);
            }

            switch ($command['op']) {
                case 'preset':
                    if (!is_string($command['preset'] ?? null)) {
                        return sprintf('commands[%d].preset must be a preset name.', $index);
                    }

                    $commands[] = ['op' => 'preset', 'preset' => $command['preset'], 'reset' => true];
                    $hasPreset = true;

                    break;

                case 'configure':
                    $features = $command['features'] ?? null;

                    if (!is_array($features) || array_diff(array_keys($command), ['op', 'features']) !== []) {
                        return sprintf('commands[%d]: a scan may only configure "features".', $index);
                    }

                    foreach ($features as $on) {
                        if ($on !== true) {
                            return sprintf('commands[%d].features: a scan switches features ON only — every value must be true.', $index);
                        }
                    }

                    if ($features !== []) {
                        $commands[] = ['op' => 'configure', 'features' => $features];
                    }

                    break;

                case 'layout':
                    if (array_diff(array_keys($command), ['op', 'boxes', 'order', 'styles']) !== []) {
                        return sprintf('commands[%d]: a layout command takes boxes, order and styles.', $index);
                    }

                    /** @var array<string, mixed> $command */
                    $commands[] = $command;

                    break;

                default:
                    return sprintf('commands[%d].op must be "preset", "configure" or "layout".', $index);
            }
        }

        if (!$hasPreset) {
            // The candidate always starts from a preset's default.
            array_unshift($commands, ['op' => 'preset', 'preset' => $calendar->layoutPreset ?? 'photoLeft', 'reset' => true]);
        }

        return $commands;
    }

    /**
     * @return array{issues: list<array<string, mixed>>, blocked: bool, bytes: null|string, width: null|int, height: null|int, design: null|array<string, mixed>, answer: array<string, mixed>}
     */
    private static function blocked(string $code, string $message): array
    {
        return [
            'issues' => [['severity' => 'error', 'stage' => 'model', 'code' => $code, 'path' => '', 'message' => mb_substr($message, 0, 600)]],
            'blocked' => true,
            'bytes' => null,
            'width' => null,
            'height' => null,
            'design' => null,
            'answer' => [],
        ];
    }
}
