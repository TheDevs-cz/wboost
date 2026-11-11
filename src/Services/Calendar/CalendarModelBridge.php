<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Entity\Project;
use WBoost\Web\Exceptions\CalendarModelRefused;
use WBoost\Web\Exceptions\CalendarModelUnavailable;
use WBoost\Web\Value\Calendar\CalendarModelResult;

/**
 * Runs the calendar's own JavaScript model on the server
 * (`resources/calendar/model.mjs`, docs/architecture/calendars.md
 * § "Server-side model").
 *
 * **Why.** The browser generates pages and owns every default; the server
 * stores and guards. A calendar changed without a browser (the MCP tools)
 * still needs a preset's default layout, the cascade of a type / year / bleed
 * change, the clamping of style values, the page list. Those rules stay in
 * ONE place — `assets/calendar/**` — and are executed by `node`, which the
 * application image ships (the same base image CI runs the node suites on).
 * The script drives the pure store exactly as the workspace does and answers
 * the save payload the workspace would have POSTed.
 *
 * **What this class never does**: write. The payload is handed back; the
 * caller sends it through `SaveCalendar` — the one write path — where the
 * server's own rules validate it again.
 *
 * A spawn costs ~60–100 ms; it happens once per MCP calendar call, never on a
 * web request.
 */
final class CalendarModelBridge
{
    private const float TIMEOUT_SECONDS = 20.0;

    private null|string $node = null;

    public function __construct(
        private readonly CalendarDocument $document,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $commands model commands, applied in order (see the script's header)
     * @param null|array{bleed?: bool, marks?: bool} $view ask for the mm view box of a page rendered with these options
     *
     * @throws CalendarModelRefused a command asked for something the model does not allow
     * @throws CalendarModelUnavailable node is missing / failed
     */
    public function run(Calendar $calendar, array $commands = [], bool $pages = false, bool $warnings = false, null|array $view = null): CalendarModelResult
    {
        return $this->runDocument(
            $this->document->calendar($calendar),
            $this->document->assets($calendar),
            $calendar->project,
            $commands,
            $pages,
            $warnings,
            $view,
        );
    }

    /**
     * The same for a calendar document that is not (yet) a row — the dry run
     * before a calendar is created.
     *
     * @param array<string, mixed> $calendarDocument {@see CalendarDocument::calendar()}
     * @param list<array<string, mixed>> $commands
     * @param null|array{bleed?: bool, marks?: bool} $view
     *
     * @throws CalendarModelRefused
     * @throws CalendarModelUnavailable
     */
    public function runDocument(array $calendarDocument, \stdClass $assets, Project $project, array $commands = [], bool $pages = false, bool $warnings = false, null|array $view = null): CalendarModelResult
    {
        $input = [
            'root' => $this->projectDir,
            'calendar' => $calendarDocument,
            'assets' => $assets,
            'env' => [
                'fonts' => $this->document->fonts($project),
                'palette' => $this->document->palette($project),
                'currentYear' => $this->document->currentYear(),
            ],
            'commands' => $commands,
            'pages' => $pages,
            'warnings' => $warnings,
        ];

        if ($view !== null) {
            $input['view'] = (object) $view;
        }

        try {
            $json = json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException $exception) {
            throw new CalendarModelRefused('invalid_design', 'The request holds text that is not valid UTF-8.');
        }

        $process = new Process([$this->node(), $this->projectDir . '/resources/calendar/model.mjs']);
        $process->setInput($json);
        $process->setTimeout(self::TIMEOUT_SECONDS);

        try {
            $process->mustRun();
        } catch (ProcessException $exception) {
            throw new CalendarModelUnavailable('The calendar model could not be run: ' . mb_substr($exception->getMessage(), 0, 400), 0, $exception);
        }

        $result = json_decode($process->getOutput(), false, 128);

        if (!$result instanceof \stdClass || !is_bool($result->ok ?? null)) {
            throw new CalendarModelUnavailable('The calendar model answered something unreadable.');
        }

        if ($result->ok !== true) {
            $code = is_string($result->code ?? null) ? $result->code : 'model_error';
            $message = is_string($result->message ?? null) ? $result->message : 'The calendar model failed.';

            if ($code === 'model_error' || $code === 'node_too_old') {
                throw new CalendarModelUnavailable('The calendar model failed: ' . mb_substr($message, 0, 600));
            }

            throw new CalendarModelRefused($code, $message);
        }

        $design = $result->design ?? null;

        if (!$design instanceof \stdClass) {
            throw new CalendarModelUnavailable('The calendar model answered no design.');
        }

        $payload = $result->payload ?? null;

        /** @var null|list<array{index: int, key: string, kind: string, title: string, subtitle: string, from?: string, to?: string}> $pageList */
        $pageList = isset($result->pages) ? json_decode((string) json_encode($result->pages), true) : null;
        /** @var null|list<array{page: string, kind: string, message: string}> $warningList */
        $warningList = isset($result->warnings) ? json_decode((string) json_encode($result->warnings), true) : null;
        /** @var null|array{x: float, y: float, w: float, h: float} $viewBox */
        $viewBox = isset($result->view) ? json_decode((string) json_encode($result->view), true) : null;

        $notes = [];

        foreach (is_array($result->notes ?? null) ? $result->notes : [] as $note) {
            if (is_string($note)) {
                $notes[] = $note;
            }
        }

        return new CalendarModelResult(
            payload: $payload instanceof \stdClass
                ? (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
                : null,
            notes: $notes,
            design: $design,
            pages: $pageList,
            warnings: $warningList,
            view: $viewBox,
        );
    }

    private function node(): string
    {
        if ($this->node === null) {
            $node = (new ExecutableFinder())->find('node');

            if ($node === null) {
                throw new CalendarModelUnavailable('node is not installed on this server, so the calendar model cannot be run.');
            }

            $this->node = $node;
        }

        return $this->node;
    }
}
