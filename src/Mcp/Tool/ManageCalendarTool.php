<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Entity\CalendarExport;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Mcp\Fill\CalendarLoader;
use WBoost\Web\Mcp\Fill\CalendarPresenter;
use WBoost\Web\Mcp\Fill\CalendarWriter;
use WBoost\Web\Exceptions\CalendarExportNotFound;
use WBoost\Web\Mcp\Response\CalendarDesignResponse;
use WBoost\Web\Mcp\Response\CalendarExportResponse;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpToolScope;
use WBoost\Web\Message\Calendar\CreateCalendar;
use WBoost\Web\Message\Calendar\RecordCalendarHistory;
use WBoost\Web\Message\Calendar\RegenerateCalendarClientLink;
use WBoost\Web\Repository\CalendarExportRepository;
use WBoost\Web\Repository\CalendarRepository;
use WBoost\Web\Services\Calendar\CalendarAccess;
use WBoost\Web\Services\Calendar\CalendarContentSeed;
use WBoost\Web\Services\Calendar\CalendarDocument;
use WBoost\Web\Services\Calendar\CalendarExports;
use WBoost\Web\Services\Calendar\CalendarModelBridge;
use WBoost\Web\Services\Calendar\DescribeCalendarChange;
use WBoost\Web\Services\Calendar\ImportCalendarLayout;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Value\Calendar\CalendarExportStatus;
use WBoost\Web\Value\Calendar\CalendarExportVariant;

/**
 * `manage_calendar` — a calendar's life and settings: one tool with an
 * `action`, like `manage_document`, because five near-identical tools would
 * compete for the same request. Details: docs/architecture/calendars.md § MCP.
 *
 * Every action is the web's own: `create` dispatches `CreateCalendar`,
 * `configure` / `finish` / `import_layout` end in `SaveCalendar` through
 * {@see CalendarWriter} (the calendar model computes the payload, the handler
 * validates and stores it), `client_link` dispatches what the "Odkaz pro
 * klienta" dialog does. There is deliberately NO delete: deleting a calendar
 * stays on the web.
 *
 * All of it is the designer's — {@see CalendarWriter::designer()} — except the
 * two export actions: on the web whoever may open a calendar may take it away
 * as a PDF, so `export_pdf` / `export_status` ask for no role. They live here
 * (write scope) rather than beside `render_calendar_page` because an export is
 * not a look: it is recorded in the history and stores a file.
 */
#[McpToolScope(McpScope::CalendarsWrite)]
readonly final class ManageCalendarTool
{
    private const array ACTIONS = ['create', 'configure', 'finish', 'client_link', 'import_layout', 'export_pdf', 'export_status'];

    public function __construct(
        private CalendarLoader $loader,
        private CalendarWriter $writer,
        private CalendarPresenter $presenter,
        private CalendarModelBridge $model,
        private CalendarRepository $calendarRepository,
        private CalendarDocument $document,
        private ImportCalendarLayout $importLayout,
        private MessageBusInterface $bus,
        private Security $security,
        private ProvideIdentity $provideIdentity,
        private CalendarExports $exports,
        private CalendarExportRepository $exportRepository,
        private CalendarAccess $access,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Creates a desk calendar or changes its settings — everything of the
     * web's step 1 ("Typ") plus finishing, the client link and taking over
     * another calendar's layout. Designer role only (an account the project is
     * merely shared with is refused: designer_only). The layout and its
     * styles are set_calendar_layout; content is update_calendar_content.
     * Calendars cannot be deleted from an AI client.
     *
     * Actions:
     * - "create": projectId (+ optionally name, year, type, features, print,
     *   preset — applied at once). Without them the calendar is what the web
     *   creates: "Stolní kalendář <next year>", next year, type "week", no
     *   layout. Pass preset to give it a layout in the same call. The answer's
     *   calendarId is the new calendar.
     * - "configure": calendarId and any of name, year, type, features, print,
     *   linkElements. Omitted settings stay.
     * - "finish": calendarId — "Dokončit": marks the look as done (finished:
     *   true), the web then opens the calendar in step 5 (Náhled) and the
     *   client can start filling it in. Needs a layout (no_layout otherwise).
     *   Calling it again is harmless.
     * - "client_link": calendarId (+ regenerate: true) — answers clientUrl, the
     *   public link a client fills the calendar in through WITHOUT an account.
     *   regenerate: true makes a new link and kills the old one at once — only
     *   when the user asks for it. Treat the link as a secret: give it to the
     *   user, nobody else.
     * - "import_layout": calendarId, fromCalendarId — takes over the layout
     *   (boxes, order, styles, its pictures are copied) of ANOTHER calendar of
     *   the same project and the same type, replacing this calendar's layout.
     *   Content is not touched.
     * - "export_pdf": calendarId, variant — starts a PDF export of the WHOLE
     *   calendar as it is saved now and answers export {exportId, status, …}.
     *   variant "print" = every page with the calendar's print settings (bleed
     *   and crop marks when they are on), photos at their uploaded resolution,
     *   WITHOUT the row of binding holes (they are punched at the bindery) —
     *   the file for the print shop; "approval" = trimmed pages as the finished
     *   calendar looks (binding holes drawn) with screen-size photos — a small
     *   file to e-mail for approval; "print_cmyk" = the "print" pages with
     *   every colour converted to CMYK for offset print (PDF/X-4, output
     *   intent Coated FOGRA39): neutral colours (R = G = B) and dark, nearly
     *   neutral text (the default text colours) in black ink only — set a
     *   text to #000000 for K 100 —, colours of the project palette in the
     *   palette's own CMYK values,
     *   everything else and the photos through the ICC profile, crop marks in
     *   the registration colour — use it when the print shop asks for CMYK;
     *   it takes several seconds per photo page and the file is about twice
     *   as large. Text and shapes are vector in all three; "print" and
     *   "approval" are RGB. The export runs in the background (about a second
     *   per page): poll "export_status" every few seconds until status is "done",
     *   then give export.downloadUrl to the user. An unchanged calendar is
     *   answered at once with the file that already exists. Any role may
     *   export (also an account the project is only shared with). It is
     *   recorded in the history. Needs a layout.
     * - "export_status": calendarId, exportId — where that export is.
     *
     * Settings:
     * - name: 1–60 characters.
     * - year: 2000–2100. Changing it moves every dated content item by the
     *   year difference, drops items dated 29 February when the new year has
     *   none, clears manual moon edits, and renames a calendar still called
     *   "Stolní kalendář <year>". Photos and captions stay with their week
     *   number.
     * - type: "week" = 7 days per page (presets photoLeft / photoRight),
     *   "fortnight" = 14 days per page (preset twoSided only). Changing the
     *   type of a calendar that has a layout resets every element POSITION to
     *   the default layout of the new type; styles are kept.
     * - features: any of the ten switches, true / false — captions (photo
     *   caption), weekNumbers, moon (moon phases), timeChange (summer / winter
     *   time), seasons (first day of a season), waste (waste collection),
     *   events ("Upravitelné akce"), shifts ("Šichtovnice"), specialPages
     *   (intro, contacts, year overview, waste plan, uploaded pages), qr.
     *   A new calendar has captions, weekNumbers and moon on. Content of a
     *   switched-off feature is kept but not printed — switch the feature ON
     *   before (or after) filling its content.
     * - print: {bleed: bool, bleedMm: 0–10 in 0.5 steps, marks: bool} — the
     *   bleed ("spadávka") around the trim and the crop marks; default 5 mm
     *   with marks. Changing the bleed moves every element edge that sits on
     *   the page edge to the new bleed edge.
     * - linkElements: bool — the editor's "Propojit prvky" switch (neighbouring
     *   elements resize together when dragged on the web). No effect on MCP
     *   edits.
     * The page size (310 × 145 mm, binding band, holes) has no setting — not
     * on the web either.
     *
     * Returns the calendar's design as stored now (every default filled in),
     * changed (what this call changed), notes (what happened beyond the literal
     * request — read them), recorded (history lines added) and url. Errors end
     * with a reason code in parentheses: invalid_action, missing_argument,
     * nothing_to_do, invalid_project_id, project_not_found,
     * invalid_calendar_id, calendar_not_found, designer_only, invalid_name,
     * invalid_year, invalid_type, unknown_feature, invalid_print,
     * unknown_preset, preset_type_mismatch, no_layout, not_a_sibling,
     * layout_type_mismatch, source_has_no_layout, invalid_variant,
     * export_not_found, invalid_design (the server's own rule, quoted in
     * Czech), model_unavailable (retry).
     *
     * Example: {"action": "create", "projectId": "…", "name": "Kalendář obce
     * Lhota 2027", "year": 2027, "type": "week", "features": {"events": true,
     * "waste": true}, "preset": "photoLeft"}
     *
     * @param string $action create | configure | finish | client_link | import_layout | export_pdf | export_status
     * @param null|string $projectId For "create": UUID of the project (list_calendars calendars[].projectId, or get_context projects[].id).
     * @param null|string $calendarId For every action except "create": UUID of the calendar (list_calendars calendars[].id).
     * @param null|string $name For "create" / "configure": the calendar's name, 1–60 characters.
     * @param null|int $year For "create" / "configure": the calendar year, 2000–2100.
     * @param null|string $type For "create" / "configure": "week" (7 days per page) or "fortnight" (14 days per page).
     * @param null|array<array-key, mixed> $features For "create" / "configure": feature switches to change, e.g. {"events": true, "waste": true}.
     * @param null|array<array-key, mixed> $print For "create" / "configure": {bleed?, bleedMm?, marks?}.
     * @param null|string $preset For "create" only: "photoLeft" | "photoRight" (type week) or "twoSided" (type fortnight) — gives the new calendar its default layout. Later: set_calendar_layout.
     * @param null|bool $linkElements For "configure": the editor's "Propojit prvky" switch.
     * @param null|string $fromCalendarId For "import_layout": UUID of the calendar whose layout to take over (same project, same type).
     * @param null|bool $regenerate For "client_link": true replaces the link with a new one — the old link stops working immediately.
     * @param null|string $variant For "export_pdf": "print" (for the print shop, RGB), "print_cmyk" (for the print shop, CMYK / PDF/X-4) or "approval" (small file for approval).
     * @param null|string $exportId For "export_status": export.exportId of an earlier "export_pdf".
     */
    #[McpTool(name: 'manage_calendar')]
    public function __invoke(
        #[Schema(enum: self::ACTIONS)]
        string $action,
        null|string $projectId = null,
        null|string $calendarId = null,
        null|string $name = null,
        null|int $year = null,
        #[Schema(enum: ['week', 'fortnight'])]
        null|string $type = null,
        #[Schema(type: 'object', additionalProperties: true)]
        null|array $features = null,
        #[Schema(type: 'object', additionalProperties: true)]
        null|array $print = null,
        #[Schema(enum: ['photoLeft', 'photoRight', 'twoSided'])]
        null|string $preset = null,
        null|bool $linkElements = null,
        null|string $fromCalendarId = null,
        null|bool $regenerate = null,
        #[Schema(enum: ['print', 'print_cmyk', 'approval'])]
        null|string $variant = null,
        null|string $exportId = null,
    ): CalendarDesignResponse {
        $configure = self::configureCommand($name, $year, $type, $features, $print, $linkElements);

        return match ($action) {
            'create' => $this->create(self::required($projectId, 'projectId', $action), $configure, $preset),
            'configure' => $this->configure(self::required($calendarId, 'calendarId', $action), $configure),
            'finish' => $this->finish(self::required($calendarId, 'calendarId', $action)),
            'client_link' => $this->clientLink(self::required($calendarId, 'calendarId', $action), $regenerate === true),
            'import_layout' => $this->importLayout(self::required($calendarId, 'calendarId', $action), self::required($fromCalendarId, 'fromCalendarId', $action)),
            'export_pdf' => $this->exportPdf(self::required($calendarId, 'calendarId', $action), self::required($variant, 'variant', $action)),
            'export_status' => $this->exportStatus(self::required($calendarId, 'calendarId', $action), self::required($exportId, 'exportId', $action)),
            default => throw new ToolCallException(sprintf('action must be one of: %s. (invalid_action)', implode(', ', self::ACTIONS))),
        };
    }

    /**
     * @param null|array<string, mixed> $configure
     */
    private function create(string $projectId, null|array $configure, null|string $preset): CalendarDesignResponse
    {
        $project = $this->loader->project($projectId);

        if (!$this->security->isGranted(ProjectVoter::EDIT, $project)) {
            throw CalendarWriter::designerOnly();
        }

        $commands = self::commands($configure, $preset);

        // The settings are checked on a calendar that does not exist yet, so a
        // refused request creates nothing.
        if ($commands !== []) {
            try {
                $this->model->runDocument($this->blank($project), new \stdClass(), $project, $commands);
            } catch (\Throwable $failure) {
                throw CalendarWriter::refusal($failure);
            }
        }

        $calendarId = $this->provideIdentity->next();
        $user = $this->security->getUser();

        $this->bus->dispatch(new CreateCalendar($calendarId, $project->id, $user instanceof User ? $user->id : null));

        $calendar = $this->calendarRepository->get($calendarId);
        $actor = $this->writer->designer($calendar);

        try {
            $applied = $this->writer->apply($calendar, $actor, $commands, known: []);
        } catch (ToolCallException $refused) {
            throw new ToolCallException(sprintf(
                'The calendar WAS created (calendarId %s, with the web\'s defaults), but its settings were refused: %s Fix them with manage_calendar "configure" / set_calendar_layout on that calendar — do not create another one.',
                $calendarId->toString(),
                $refused->getMessage(),
            ));
        }

        return $this->presenter->design($calendar, $applied['model'], ['created', ...$applied['changed']], $applied['model']->notes, $applied['recorded']);
    }

    /**
     * @param null|array<string, mixed> $configure
     */
    private function configure(string $calendarId, null|array $configure): CalendarDesignResponse
    {
        if ($configure === null) {
            throw new ToolCallException('"configure" needs at least one of name, year, type, features, print, linkElements. (nothing_to_do)');
        }

        $calendar = $this->loader->viewable($calendarId);
        $applied = $this->writer->apply($calendar, $this->writer->designer($calendar), [$configure]);

        return $this->presenter->design($calendar, $applied['model'], $applied['changed'], $applied['model']->notes, $applied['recorded']);
    }

    private function finish(string $calendarId): CalendarDesignResponse
    {
        $calendar = $this->loader->viewable($calendarId);
        $actor = $this->writer->designer($calendar);

        if ($calendar->layout === null) {
            throw new ToolCallException('The calendar has no layout yet, so it cannot be finished. Choose one first: set_calendar_layout with preset "photoLeft", "photoRight" (7 days) or "twoSided" (14 days). (no_layout)');
        }

        $wasFinished = $calendar->finished;
        $applied = $this->writer->apply($calendar, $actor, [], finish: true);

        return $this->presenter->design(
            $calendar,
            $applied['model'],
            $wasFinished ? [] : ['finished', 'step'],
            $wasFinished ? ['The calendar was already finished; the look was saved again.'] : [],
            $applied['recorded'],
        );
    }

    private function clientLink(string $calendarId, bool $regenerate): CalendarDesignResponse
    {
        $calendar = $this->loader->viewable($calendarId);
        $actor = $this->writer->designer($calendar);
        $known = $this->writer->historyIds($calendar);

        try {
            $model = $this->model->run($calendar);
        } catch (\Throwable $failure) {
            throw CalendarWriter::refusal($failure);
        }

        // What the web's "Odkaz pro klienta" dialog does: a new token, or the
        // line that says the link was handed out.
        $this->bus->dispatch($regenerate
            ? new RegenerateCalendarClientLink($calendar->id, $actor)
            : new RecordCalendarHistory($calendar->id, $actor, DescribeCalendarChange::LINK_COPIED));

        return $this->presenter->design(
            $calendar,
            $model,
            $regenerate ? ['clientLink'] : [],
            $regenerate ? ['A new client link was generated — the previous link no longer works.'] : [],
            $this->writer->recordedSince($calendar, $known),
            $this->document->clientUrl($calendar),
        );
    }

    private function exportPdf(string $calendarId, string $variant): CalendarDesignResponse
    {
        $calendar = $this->loader->viewable($calendarId);
        $chosen = CalendarExportVariant::tryFrom($variant);

        if ($chosen === null) {
            throw new ToolCallException('variant must be "print" (every page with bleed and crop marks as set, full-resolution photos, RGB), "print_cmyk" (the same in CMYK for offset print, PDF/X-4) or "approval" (trimmed pages, a small file). (invalid_variant)');
        }

        if ($calendar->layout === null) {
            throw new ToolCallException('The calendar has no layout yet, so there is nothing to export. Choose one first: set_calendar_layout with preset "photoLeft", "photoRight" (7 days) or "twoSided" (14 days). (no_layout)');
        }

        $known = $this->writer->historyIds($calendar);

        try {
            $model = $this->model->run($calendar);
            // The web's own request: reuse of an unchanged export, the history line, the job.
            $export = $this->exports->request($calendar, $chosen, $this->access->forUser($calendar));
        } catch (\Throwable $failure) {
            throw CalendarWriter::refusal($failure);
        }

        return $this->presenter->design(
            $calendar,
            $model,
            [],
            [$export->isDownloadable()
                ? 'The PDF is ready: give export.downloadUrl to the user.'
                : 'The export runs in the background. Call manage_calendar "export_status" with export.exportId every few seconds until status is "done" (or "failed").'],
            $this->writer->recordedSince($calendar, $known),
            export: $this->describeExport($export),
        );
    }

    private function exportStatus(string $calendarId, string $exportId): CalendarDesignResponse
    {
        $calendar = $this->loader->viewable($calendarId);

        try {
            // looked up inside ITS calendar: an id of another calendar's export is unknown here
            $export = $this->exportRepository->getOfCalendar($calendar->id, Uuid::isValid($exportId) ? Uuid::fromString($exportId) : throw new CalendarExportNotFound());
        } catch (CalendarExportNotFound) {
            throw new ToolCallException('This calendar has no export with that id (only the newest few are kept). Start a new one with manage_calendar "export_pdf". (export_not_found)');
        }

        try {
            $model = $this->model->run($calendar);
        } catch (\Throwable $failure) {
            throw CalendarWriter::refusal($failure);
        }

        return $this->presenter->design($calendar, $model, [], [], [], export: $this->describeExport($export));
    }

    private function describeExport(CalendarExport $export): CalendarExportResponse
    {
        $stalled = $export->isStale($this->clock->now());
        $failed = $stalled || $export->status === CalendarExportStatus::Failed;

        return new CalendarExportResponse(
            exportId: $export->id->toString(),
            variant: $export->variant->value,
            status: $failed ? CalendarExportStatus::Failed->value : $export->status->value,
            pagesDone: $export->progressDone,
            pagesTotal: $export->progressTotal,
            revision: $export->calendarRevision,
            fileName: CalendarExports::fileName($export->calendar, $export->variant)->name,
            sizeBytes: $export->isDownloadable() ? $export->sizeBytes : null,
            // An AI client has no web session: the file is handed out by its storage address
            // (an unguessable key; the object carries its own download name).
            downloadUrl: $export->isDownloadable() ? $this->exports->storageUrl($export) : null,
            error: $failed ? ($stalled ? CalendarExports::STALLED : ($export->error ?? CalendarExports::FAILED)) : null,
        );
    }

    private function importLayout(string $calendarId, string $fromCalendarId): CalendarDesignResponse
    {
        $calendar = $this->loader->viewable($calendarId);
        $actor = $this->writer->designer($calendar);
        $source = $this->loader->viewable($fromCalendarId);

        if ($source->id->equals($calendar->id) || !$source->project->id->equals($calendar->project->id)) {
            throw new ToolCallException('fromCalendarId must be ANOTHER calendar of the same project — a layout and its pictures never cross projects. list_calendars with this calendar\'s projectId shows the candidates. Nothing was saved. (not_a_sibling)');
        }

        if ($source->type !== $calendar->type) {
            throw new ToolCallException(sprintf(
                'The layout is for another type: "%s" is a %s calendar, this one is %s. Change this calendar\'s type first (manage_calendar "configure") or pick another source. Nothing was saved. (layout_type_mismatch)',
                $source->name,
                $source->type->value,
                $calendar->type->value,
            ));
        }

        if ($source->layout === null) {
            throw new ToolCallException(sprintf('The calendar "%s" has no layout to take over yet. Nothing was saved. (source_has_no_layout)', $source->name));
        }

        $known = $this->writer->historyIds($calendar);

        // As the web's "Nahrát layout": the pictures the layout uses are
        // copied into this calendar under new ids, the import is noted in the
        // history, and the layout is then applied and saved like any change.
        $plan = $this->importLayout->plan($calendar, $source->layout, $source, $actor);

        foreach ($plan['copies'] as $copy) {
            $this->bus->dispatch($copy);
        }

        $this->bus->dispatch(new RecordCalendarHistory($calendar->id, $actor, DescribeCalendarChange::layoutFromCalendar($source->name)));

        $applied = $this->writer->apply($calendar, $actor, [['op' => 'replace_layout', 'layout' => $plan['layout']]], known: $known);

        return $this->presenter->design(
            $calendar,
            $applied['model'],
            $applied['changed'],
            [sprintf('The layout of "%s" replaced this calendar\'s layout; %d picture(s) were copied.', $source->name, count($plan['copies'])), ...$applied['model']->notes],
            $applied['recorded'],
        );
    }

    /**
     * The calendar document `CreateCalendar` is about to store, for the dry
     * run of the settings. Only its shape matters here — the real calendar is
     * created by the web's handler.
     *
     * @return array<string, mixed>
     */
    private function blank(Project $project): array
    {
        $year = $this->document->currentYear() + 1;

        return [
            'id' => '',
            'revision' => 0,
            'name' => sprintf('Stolní kalendář %d', $year),
            'year' => $year,
            'type' => 'week',
            'page' => new \stdClass(),
            'print' => new \stdClass(),
            'features' => new \stdClass(),
            'preset' => null,
            'layout' => null,
            'linkElements' => true,
            'step' => 1,
            'finished' => false,
            'content' => (object) array_map(
                static fn (mixed $collection): mixed => is_array($collection) ? (object) $collection : $collection,
                CalendarContentSeed::initial(),
            ),
        ];
    }

    /**
     * @param null|array<array-key, mixed> $features
     * @param null|array<array-key, mixed> $print
     * @return null|array<string, mixed> the model's `configure` command; null when nothing was asked for
     */
    private static function configureCommand(null|string $name, null|int $year, null|string $type, null|array $features, null|array $print, null|bool $linkElements): null|array
    {
        $command = ['op' => 'configure'];

        if ($name !== null) {
            $command['name'] = $name;
        }

        if ($year !== null) {
            $command['year'] = $year;
        }

        if ($type !== null) {
            $command['type'] = $type;
        }

        if ($features !== null && $features !== []) {
            $command['features'] = array_is_list($features) ? $features : (object) $features;
        }

        if ($print !== null && $print !== []) {
            $command['print'] = array_is_list($print) ? $print : (object) $print;
        }

        if ($linkElements !== null) {
            $command['linkElements'] = $linkElements;
        }

        return count($command) > 1 ? $command : null;
    }

    /**
     * @param null|array<string, mixed> $configure
     * @return list<array<string, mixed>>
     */
    private static function commands(null|array $configure, null|string $preset): array
    {
        $commands = [];

        if ($configure !== null) {
            $commands[] = $configure;
        }

        if ($preset !== null && trim($preset) !== '') {
            $commands[] = ['op' => 'preset', 'preset' => $preset];
        }

        return $commands;
    }

    private static function required(null|string $value, string $name, string $action): string
    {
        if ($value === null || trim($value) === '') {
            throw new ToolCallException(sprintf('"%s" needs %s. (missing_argument)', $action, $name));
        }

        return $value;
    }
}
