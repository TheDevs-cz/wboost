<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Tool;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemReader;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use WBoost\Web\Entity\AiScan;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpToolScope;
use WBoost\Web\Message\AiScan\AcceptCalendarScan;
use WBoost\Web\Message\AiScan\AcceptTemplateScan;
use WBoost\Web\Message\AiScan\ApplyAiScanFix;
use WBoost\Web\Message\AiScan\CancelAiScan;
use WBoost\Web\Message\AiScan\ContinueAiScan;
use WBoost\Web\Message\AiScan\RequestAiScan;
use WBoost\Web\Message\AiScan\RestoreCalendarScan;
use WBoost\Web\Message\AiScan\RetryAiScan;
use WBoost\Web\Repository\AiScanRepository;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\AiScan\AiScanAccess;
use WBoost\Web\Services\AiScan\AiScanCredits;
use WBoost\Web\Services\AiScan\AiScanLists;
use WBoost\Web\Services\AiScan\AiScanNotifications;
use WBoost\Web\Services\AiScan\AiScans;
use WBoost\Web\Services\AiScan\AiScanStorage;
use WBoost\Web\Services\AiScan\AiScanUrls;
use WBoost\Web\Services\AiScan\Calendar\CalendarScanProposalView;
use WBoost\Web\Services\AiScan\Template\ScanProposalView;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\CalendarVoter;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Value\AiScan\AiScanChannel;
use WBoost\Web\Value\AiScan\AiScanKind;
use WBoost\Web\Value\AiScan\AiScanRefusal;
use WBoost\Web\Value\AiScan\AiScanRefused;
use WBoost\Web\Value\AiScan\AiScanStatus;
use WBoost\Web\Value\DimensionUnit;

/**
 * `scan_design` — AI scan over MCP (docs/ai-scan/ux.md §10): a flat picture
 * of a finished design becomes a proposal, and the proposal a template — or,
 * for a picture of a desk-calendar page, the layout of a calendar.
 *
 * One tool, one argument `action`. It is the web wizard with the same rules,
 * limits and counting — every action goes through the messages the web
 * controllers dispatch, with channel `mcp` in usage:
 *
 * | action | web twin |
 * |---|---|
 * | `start` | the upload (`RequestAiScan`) |
 * | `status` | the status poll |
 * | `result` | the review's document |
 * | `apply` | fixes + pictures + "Vytvořit šablonu" (`ApplyAiScanFix`, `AcceptTemplateScan`); calendars: „Použít rozvržení“ (`AcceptCalendarScan`) |
 * | `list` | "Historie skenů" |
 * | `cancel` / `retry` / `continue` | "Zrušit sken" / "Zkusit znovu" / "Zkusit přesto" |
 * | `restore` | calendars only: „Vrátit původní rozvržení“ (`RestoreCalendarScan`) |
 *
 * Web-only by decision (O12): the admin switch, the access request, discard,
 * the operating mode.
 *
 * **Authorisation**: scope `ai:scan` (off by default), then per action the
 * web's two permissions — START (`start`, `retry`, `continue`: an admin, or
 * an editor of a project with AI scan on) and USE (everything on an existing
 * scan: an admin, the requester, an editor of its project). `start` also
 * requires that the user agreed to the consent text **in the web** — an
 * agent cannot agree for them. Applying or restoring a CALENDAR scan also
 * needs the designer's role on that calendar (the web's `calendar_edit`).
 *
 * **Refusals** are tool errors whose message starts with the code of
 * architecture.md §5.6 (`limit_reached: …`). Nothing is charged for a
 * refused start.
 */
#[McpToolScope(McpScope::AiScan)]
readonly final class ScanDesignTool
{
    private const array ACTIONS = ['start', 'status', 'result', 'apply', 'list', 'cancel', 'retry', 'continue', 'restore'];
    /** About what a base64 payload can carry through the transport's 4 MiB body. */
    private const int MAX_INLINE_BYTES = 3_000_000;
    private const int POLL_SECONDS = 5;

    public function __construct(
        private MessageBusInterface $bus,
        private Security $security,
        private ClockInterface $clock,
        private ProjectRepository $projectRepository,
        private AiScanRepository $scanRepository,
        private AiScanAccess $access,
        private AiScans $scans,
        private AiScanLists $lists,
        private AiScanCredits $credits,
        private AiScanNotifications $notifications,
        private AiScanStorage $storage,
        private AiScanUrls $urls,
        private ScanProposalView $proposalView,
        private CalendarScanProposalView $calendarProposalView,
        private ProvideIdentity $provideIdentity,
        private \WBoost\Web\Repository\FileUploadRepository $files,
        #[Autowire(service: 'oneup_flysystem.minio_filesystem')]
        private FilesystemReader $filesystem,
    ) {
    }

    /**
     * AI SCAN: turns a flat picture of a finished design (an export, a
     * screenshot — JPG, PNG, WebP or HEIC; or a PDF, whose FIRST page is
     * scanned as a picture) into an editable wboost template:
     * texts, shapes, colours and PLACES FOR PICTURES. Pictures are never taken
     * out of the image — each one becomes a named place you fill with a
     * gallery picture or leave for later. A scan is a background job that
     * usually takes 1–3 minutes (up to about 12) and produces a PROPOSAL;
     * nothing exists among the templates until you call "apply".
     *
     * With kind "calendar" it turns a picture of ONE PAGE of a desk calendar
     * into the LAYOUT of an existing calendar (calendarId): where the photo,
     * the day rows, the header, the month and the logo sit, colours, fonts
     * chosen from the project. The layout is built for the calendar's own
     * type: a 7-day calendar ("week") gets a page with one week column, a
     * 14-day one ("fortnight") the two-sided page with a week column on each
     * side of the photo — so scan a two-week page for a 14-day calendar and a
     * one-week page for a 7-day one; when they disagree the result says so
     * (notes "fortnight_left_half" / "week_page_on_fortnight") and only one
     * week of the picture is used. The calendar does not change
     * until "apply"; "apply" saves the layout at once and keeps the previous
     * one, which "restore" puts back.
     *
     * It is a paid, per-project feature. Each scan that delivers a proposal —
     * and each one cancelled while already running — counts against the
     * project's monthly limit; a stopped, failed or refused scan does not.
     * Do not start scans in a loop: there are daily limits per user and
     * project.
     *
     * The flow: "start" → poll "status" every pollAfterSeconds until status is
     * "ready" (or it ended: "stopped", "failed", "refused", "cancelled") →
     * "result" to read the proposal → "apply" to create the template.
     *
     * ACTIONS
     *
     * "start" — projectId, and the picture as imageId (a gallery picture of
     * that project, see list_gallery / upload_image) or imageBase64 (the
     * bytes, base64, under about 3 MB; a data: URI is accepted). Optional:
     * requestId (your own idempotency key — the same key returns the same scan
     * instead of starting another), format ({"unit": "px"|"mm"|"cm", "width":
     * n, "height": n} — the template's size when it should differ from the
     * picture's own pixels, e.g. A4 {"unit": "mm", "width": 210, "height":
     * 297}; it must have the picture's aspect ratio or it is ignored). kind is
     * "template" (default) or "calendar"; a calendar scan also needs
     * calendarId — a calendar of that project, 7-day or 14-day (list_calendars) — takes
     * no format, and does not need a project font. Returns scanId,
     * status, creditsLeft, queuePosition, estimatedStartSeconds,
     * estimatedFinishSeconds, pollAfterSeconds.
     * Refusals (nothing is charged): not_enabled (AI scan is off for this
     * project or account), consent_required (the user must first agree to the
     * data-processing text in the wboost web app — you cannot agree for
     * them), limit_reached (the project's monthly limit), daily_starts_reached,
     * project_queue_full (4 scans of the project in progress), queue_full,
     * temporarily_unavailable (the service is paused or the scanner is away —
     * try later), project_paused (three pictures of this project were refused
     * by the AI today), no_fonts (the project has no font; upload one with
     * upload_font), unsupported_image (not a JPG/PNG/WebP/HEIC/PDF, over 10 MB,
     * a side over 8000 px, over 36 megapixels, or the shorter side under
     * 600 px — a calendar page: at least 640 × 280 px), source_unscannable (this picture already failed twice),
     * wrong_state (kind "calendar" without a calendarId of a calendar of the
     * project).
     *
     * "status" — scanId. Returns status (checking | queued | running | ready |
     * accepted | discarded | cancelled | stopped | failed | refused | expired),
     * waiting (why a waiting scan does not move, or null), restarted, stage,
     * note + noteParams, round, elapsedSeconds, queuePosition,
     * estimatedStartSeconds / estimatedFinishSeconds ([from, to], null while
     * waiting), expiresAt (the proposal and the picture are deleted then),
     * unfinished (the run hit its time limit and kept its best attempt),
     * errorCode + errorSentence when it stopped or failed, retriesLeft,
     * canRetry, canContinue, pollAfterSeconds (null once it ended).
     *
     * "result" — scanId, optional image: true to also get the proposal's
     * render. Available when status is "ready" (or "accepted" while the
     * proposal exists). Returns name, counts {texts, shapes, places,
     * background}, toDecide, elements[] (id, type text|shape|place|background,
     * name, text, input = the user fills it / false = fixed text, font, size),
     * placeholders[] (id, name, role, box in source pixels, kind "fixed" =
     * part of the template | "input" = filled at every export, fit "contain" |
     * "cover", picture), decisions[] (things you may change, each with the
     * fixes[] it accepts: a substituted font, a doubtful text, a left-out
     * thing), information[] (what was changed automatically — shrunk texts,
     * colours unified with the brand palette, simplifications), fonts[] (the
     * face strings you may choose from).
     * For a CALENDAR scan it returns instead: calendarId, hasLayout (the
     * calendar has a layout that "apply" replaces), preset, layout (the whole
     * layout the calendar would get now — boxes in mm, styles), recognised[]
     * ({element, state: found | approximate | absent, kept: true when the
     * calendar keeps its current setting for an element the page does not
     * show}), featuresOffered[] (switches the page shows and the calendar
     * has off — pass the ones to turn on to "apply"), pictures[] ({slot: logo
     * | header | background, current}: places whose picture was not taken
     * from the image), fonts[], notExpressible[] ({code, box}: things on the
     * page a wboost calendar cannot draw), notes[], expiresAt, applied.
     *
     * "apply" — scanId, and optionally fixes[], placeholders[], name. Applies
     * them and CREATES the template; idempotent — called again it returns the
     * same ids. fixes[] entries: {"type": "choose_font", "group", "face"},
     * {"type": "keep_font", "group"}, {"type": "confirm_text", "id", "text"?},
     * {"type": "set_text", "id", "text"}, {"type": "set_input", "id", "input":
     * true|false}, {"type": "rename", "id", "name"}, {"type":
     * "add_placeholder", "omitted": n}, {"type": "keep_omitted", "omitted":
     * n}, {"type": "remove_placeholder", "id"}, {"type": "merge_placeholders",
     * "ids": [..]}, {"type": "placeholder_to_shape", "id", "shape":
     * "rectangle"|"circle"|"star", "colour"?: "#rrggbb"}, {"type":
     * "undo_shrink"}, {"type": "revert_palette"}. placeholders[] entries:
     * {"id", "assetId": "<gallery id>"|null, "kind"?: "fixed"|"input",
     * "fit"?: "contain"|"cover"}. name: the template's name (3–30
     * characters; default: the headline text). Returns templateId, variantId,
     * editorUrl, unfilledPictures (places left empty — they show a grey
     * stand-in and exports refuse with unfilled_pictures until filled or
     * allowed), created.
     * Refusals (nothing is created): wrong_state, invalid_fix (names the
     * entry), stale_references (a font or picture the proposal uses is gone —
     * read "result" again), renderer_busy (try again in a few seconds).
     * For a CALENDAR scan "apply" takes scanId and optionally features[] (keys
     * of "result" featuresOffered to switch on; a scan never switches anything
     * off) and SAVES the proposed layout into the calendar at once (the
     * designer's role on the calendar is needed). Idempotent (a repeat
     * answers created: false). Returns calendarId, revision, features
     * (switched on), unfilledPictures (the scan's picture places that are
     * empty in the calendar now — upload_calendar_asset, then set_calendar_layout
     * styles {"logo": {"asset": id}}), restoreUntil (until when "restore"
     * works), calendarUrl, created. Texts, photos and page content are never
     * touched.
     *
     * "list" — projectId, optional status. The project's scans, newest first.
     *
     * "cancel" — scanId. Returns status and counted: true when the scan was
     * already running (it counts against the monthly limit), false when it
     * was still waiting.
     *
     * "retry" — scanId of a "failed" scan: the same picture again, at most
     * twice per upload. Reserves a scan like "start" and can be refused like
     * it, plus no_retries_left and source_unscannable. Returns status,
     * retriesLeft.
     *
     * "continue" — scanId of a scan stopped as a photo of a print
     * (errorCode "photo_of_print"): scan it anyway, once; the result will be
     * approximate. Reserves a scan; refusals as "start".
     *
     * "restore" — scanId of an applied CALENDAR scan, optional confirm. Puts
     * back the layout, layout choice and switches the calendar had before
     * "apply" — nothing else. Works until restoreUntil. When the layout or
     * the switches were changed after "apply" it is refused with
     * layout_changed (those edits would be lost): ask the user, then repeat
     * with confirm: true. Returns calendarId, revision, restored (false = it
     * had been restored already). When several scans were applied to one
     * calendar they are restored in reverse order: only the scan applied
     * last can be restored (an earlier one answers wrong_state until then) —
     * restoring it brings back the earlier scan's layout, whose own restore
     * then works again ("result" → applied.restorable / applied.superseded).
     *
     * Any action called in a state that does not allow it answers wrong_state.
     *
     * @param string $action One of: start, status, result, apply, list, cancel, retry, continue, restore.
     * @param null|string $projectId UUID of the project (get_context) — for "start" and "list".
     * @param null|string $scanId UUID of the scan — for every action except "start" and "list".
     * @param null|string $kind "template" (default) or "calendar" — for "start".
     * @param null|string $calendarId "start" with kind "calendar": UUID of the calendar (7-day or 14-day) the layout is proposed for.
     * @param null|string $imageId "start": a gallery picture of the project to scan.
     * @param null|string $imageBase64 "start": the picture's bytes, base64 (under about 3 MB). Use imageId for anything larger.
     * @param null|string $requestId "start": idempotency key (1–64 characters); repeat it to get the same scan back.
     * @param null|array<array-key, mixed> $format "start": {"unit": "px"|"mm"|"cm", "width": n, "height": n} — the template's size, same aspect ratio as the picture.
     * @param null|string $name "apply": the template's name, 3–30 characters.
     * @param null|array<array-key, mixed> $fixes "apply": fixes to apply before creating, in order.
     * @param null|array<array-key, mixed> $placeholders "apply": pictures, kind and fit per place.
     * @param null|array<array-key, mixed> $features "apply" of a calendar scan: feature keys to switch on (from "result" featuresOffered).
     * @param null|string $status "list": only scans in this status.
     * @param bool $image "result": also return the proposal's render.
     * @param bool $confirm "restore": overwrite edits made to the layout since "apply".
     */
    #[McpTool(name: 'scan_design')]
    public function __invoke(
        #[Schema(enum: self::ACTIONS)]
        string $action,
        null|string $projectId = null,
        null|string $scanId = null,
        null|string $kind = null,
        null|string $calendarId = null,
        null|string $imageId = null,
        null|string $imageBase64 = null,
        null|string $requestId = null,
        #[Schema(type: 'object', additionalProperties: true)]
        null|array $format = null,
        null|string $name = null,
        #[Schema(type: 'array', items: ['type' => 'object'])]
        null|array $fixes = null,
        #[Schema(type: 'array', items: ['type' => 'object'])]
        null|array $placeholders = null,
        #[Schema(type: 'array', items: ['type' => 'string'])]
        null|array $features = null,
        null|string $status = null,
        bool $image = false,
        bool $confirm = false,
    ): CallToolResult {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new ToolCallException('Not authenticated.');
        }

        try {
            return match ($action) {
                'start' => $this->start($user, $projectId, $kind, $calendarId, $imageId, $imageBase64, $requestId, $format),
                'status' => self::json($this->statusOf($user, $this->scan($user, $scanId))),
                'result' => $this->result($user, $this->scan($user, $scanId), $image),
                'apply' => self::json($this->apply($user, $this->scan($user, $scanId), $fixes ?? [], $placeholders ?? [], $name, $features ?? [])),
                'list' => self::json($this->list($user, $projectId, $status)),
                'cancel' => self::json($this->cancel($user, $this->scan($user, $scanId))),
                'retry', 'continue' => self::json($this->restart($user, $this->scan($user, $scanId), $action)),
                'restore' => self::json($this->restore($user, $this->scan($user, $scanId), $confirm)),
                default => throw new ToolCallException('action must be one of: ' . implode(', ', self::ACTIONS) . '.'),
            };
        } catch (AiScanRefused $refused) {
            throw self::refused($refused->refusal, null, $refused->params);
        }
    }

    /**
     * @param null|array<array-key, mixed> $format
     */
    private function start(User $user, null|string $projectId, null|string $kind, null|string $calendarId, null|string $imageId, null|string $imageBase64, null|string $requestId, null|array $format): CallToolResult
    {
        $project = $this->project($projectId);
        $scanKind = AiScanKind::tryFrom($kind ?? AiScanKind::Template->value);

        if ($scanKind === null) {
            throw new ToolCallException('unsupported_kind: kind must be "template" or "calendar". Nothing was started.');
        }

        if ($scanKind === AiScanKind::Calendar && ($calendarId === null || !Uuid::isValid($calendarId))) {
            throw self::refused(AiScanRefusal::WrongState, 'a calendar scan needs calendarId — the UUID of a calendar of the project (list_calendars).');
        }

        if (!$this->access->isEntitled($user, $project)) {
            throw self::refused(AiScanRefusal::NotEnabled);
        }

        if (($imageId === null) === ($imageBase64 === null)) {
            throw new ToolCallException('"start" needs exactly one of imageId (a gallery picture of the project) and imageBase64 (the bytes). Nothing was started.');
        }

        [$bytes, $fileName] = $imageId !== null ? $this->galleryBytes($project, $imageId) : [self::decode($imageBase64 ?? ''), 'design'];
        $path = tempnam(sys_get_temp_dir(), 'wboost-mcp-scan-');

        if ($path === false || file_put_contents($path, $bytes) === false) {
            throw new ToolCallException('The picture could not be staged on the server. Nothing was started; try again.');
        }

        try {
            $scanId = $this->dispatch(new RequestAiScan(
                scanId: $this->provideIdentity->next(),
                requestId: self::requestId($requestId),
                projectId: $project->id,
                userId: $user->id,
                kind: $scanKind,
                channel: AiScanChannel::Mcp,
                filePath: $path,
                originalName: $fileName,
                options: $scanKind === AiScanKind::Calendar ? [] : self::options($format),
                // Consent is given in the web, by the person — never here.
                consentGiven: false,
                calendarId: $scanKind === AiScanKind::Calendar ? Uuid::fromString((string) $calendarId) : null,
            ));
        } finally {
            @unlink($path);
        }

        assert($scanId instanceof UuidInterface);
        $scan = $this->scanRepository->get($scanId);
        $view = $this->scans->view($scan);
        $limit = $project->aiScanMonthlyLimit;

        return self::json([
            'scanId' => $scan->id->toString(),
            'status' => $view['status'],
            'creditsLeft' => $limit === null ? null : max(0, $limit - $this->credits->usedInMonth($project->id->toString(), $this->clock->now())),
            'waiting' => $view['waiting'],
            'queuePosition' => $view['queuePosition'],
            'estimatedStartSeconds' => $view['estimatedStartSeconds'],
            'estimatedFinishSeconds' => $view['estimatedFinishSeconds'],
            'pollAfterSeconds' => self::POLL_SECONDS,
            'next' => 'Poll scan_design {"action": "status", "scanId": "…"} every pollAfterSeconds until status is "ready"; 1–3 minutes is normal.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function statusOf(User $user, AiScan $scan): array
    {
        $view = $this->scans->view($scan);
        $scan = $this->scans->current($scan);
        $ended = !$scan->status->isInProgress();

        // The agent got the final state for its user: that is "seen".
        if ($ended && $scan->channel === AiScanChannel::Mcp) {
            $this->notifications->seen($scan->id, $user->id);
        }

        $failed = in_array($scan->status, [AiScanStatus::Stopped, AiScanStatus::Failed, AiScanStatus::Refused], true);

        return [
            'scanId' => $scan->id->toString(),
            'projectId' => $scan->project->id->toString(),
            'kind' => $scan->kind->value,
            'calendarId' => $view['calendarId'],
            'status' => $view['status'],
            'waiting' => $view['waiting'],
            'restarted' => $view['restarted'],
            'stage' => $view['stage'],
            'note' => $view['note'],
            'noteParams' => $view['noteParams'],
            'round' => $view['round'],
            'elapsedSeconds' => max(0, ($scan->finishedAt ?? $this->clock->now())->getTimestamp() - $scan->startedAt->getTimestamp()),
            'queuePosition' => $view['queuePosition'],
            'estimatedStartSeconds' => $view['estimatedStartSeconds'],
            'estimatedFinishSeconds' => $view['estimatedFinishSeconds'],
            'expiresAt' => $view['expiresAt'],
            'unfinished' => $view['unfinished'],
            'errorCode' => $failed ? ($scan->status === AiScanStatus::Refused ? 'refused' : $scan->errorCode) : null,
            'errorSentence' => $failed ? self::sentence($scan) : null,
            'retriesLeft' => $view['retriesLeft'],
            'canRetry' => $view['canRetry'],
            'canContinue' => $view['canContinue'],
            'templateId' => $view['resultTemplateId'],
            'variantId' => $view['resultVariantId'],
            'pollAfterSeconds' => $ended ? null : self::POLL_SECONDS,
        ];
    }

    private function result(User $user, AiScan $scan, bool $image): CallToolResult
    {
        $scan = $this->scans->current($scan);
        $proposal = $scan->kind === AiScanKind::Calendar ? $this->calendarResult($scan) : $this->proposalView->view($scan);

        if ($proposal === null) {
            throw self::refused(AiScanRefusal::WrongState, sprintf('the scan is "%s" and has no proposal to read (a proposal exists while status is "ready", and on an "accepted" scan until it expires).', $scan->status->value));
        }

        if ($scan->channel === AiScanChannel::Mcp) {
            $this->notifications->seen($scan->id, $user->id);
        }

        $content = [self::text(['scanId' => $scan->id->toString(), 'status' => $scan->status->value] + $proposal)];

        if ($image) {
            $directory = AiScanStorage::directory($scan->project->id->toString(), $scan->id->toString(), $scan->storageSecret);
            $bytes = $this->storage->read($directory, AiScanStorage::PROPOSAL)
                ?? $this->storage->read($directory, AiScanStorage::candidateName(max(1, $scan->keptCandidate ?? 1)));

            if ($bytes !== null) {
                $content[] = ImageContent::fromString($bytes, 'image/webp');
            }
        }

        return new CallToolResult($content);
    }

    /**
     * @param array<array-key, mixed> $fixes
     * @param array<array-key, mixed> $placeholders
     * @param array<array-key, mixed> $features
     * @return array<string, mixed>
     */
    private function apply(User $user, AiScan $scan, array $fixes, array $placeholders, null|string $name, array $features = []): array
    {
        if ($scan->kind === AiScanKind::Calendar) {
            return $this->applyCalendar($user, $scan, $features);
        }

        // A repeat on an accepted scan answers with the first result; its fixes are not applied again.
        if ($scan->status !== AiScanStatus::Accepted) {
            $all = [];

            foreach ($fixes as $index => $fix) {
                if (!is_array($fix)) {
                    throw new ToolCallException(sprintf('invalid_fix: fixes[%s] must be an object with "type". Nothing was created.', $index));
                }

                /** @var array<string, mixed> $fix */
                $all[sprintf('fixes[%s]', $index)] = $fix;
            }

            foreach ($placeholders as $index => $placeholder) {
                $id = is_array($placeholder) && is_string($placeholder['id'] ?? null) ? $placeholder['id'] : null;

                if (!is_array($placeholder) || $id === null) {
                    throw new ToolCallException(sprintf('invalid_fix: placeholders[%s] must be an object with "id". Nothing was created.', $index));
                }

                if (array_key_exists('kind', $placeholder)) {
                    $all[sprintf('placeholders[%s].kind', $index)] = ['type' => 'set_kind', 'id' => $id, 'kind' => $placeholder['kind']];
                }

                if (array_key_exists('fit', $placeholder)) {
                    $all[sprintf('placeholders[%s].fit', $index)] = ['type' => 'set_fit', 'id' => $id, 'fit' => $placeholder['fit']];
                }

                if (array_key_exists('assetId', $placeholder)) {
                    $all[sprintf('placeholders[%s].assetId', $index)] = ['type' => 'set_picture', 'id' => $id, 'fileId' => $placeholder['assetId']];
                }
            }

            if ($name !== null) {
                $all['name'] = ['type' => 'set_name', 'name' => $name];
            }

            foreach ($all as $path => $fix) {
                try {
                    // No render per fix: accept renders the result once.
                    $this->dispatch(new ApplyAiScanFix($scan->id, $user->id, $fix, render: false));
                } catch (AiScanRefused $refused) {
                    throw self::refused($refused->refusal, sprintf('%s was refused%s. Fixes before it were saved; nothing was created.', $path, isset($refused->params['field']) ? sprintf(' (field "%s")', $refused->params['field']) : ''), $refused->params);
                }
            }
        }

        $result = $this->dispatch(new AcceptTemplateScan($scan->id, $user->id));
        assert(is_array($result));
        $variantId = is_string($result['variantId'] ?? null) ? $result['variantId'] : null;

        return [
            'scanId' => $scan->id->toString(),
            'status' => AiScanStatus::Accepted->value,
            'templateId' => $result['templateId'] ?? null,
            'variantId' => $variantId,
            'editorUrl' => $variantId === null ? null : $this->urls->editor($variantId),
            'elements' => $result['elements'] ?? 0,
            'unfilledPictures' => $result['unfilledPictures'] ?? 0,
            'created' => $result['created'] ?? false,
        ];
    }

    /**
     * The review's document of a calendar scan, in the words the tool's
     * description uses.
     *
     * @return null|array<string, mixed>
     */
    private function calendarResult(AiScan $scan): null|array
    {
        $view = $this->calendarProposalView->view($scan);

        if ($view === null || $view['layout'] === null) {
            return null;
        }

        return [
            'kind' => 'calendar',
            'calendarId' => $view['calendarId'],
            'readOnly' => $view['readOnly'],
            'unfinished' => $view['unfinished'],
            'expiresAt' => $view['expiresAt'],
            'hasLayout' => $view['hasLayout'],
            'preset' => $view['preset'],
            'layout' => $view['layout'],
            'recognised' => $view['recognised'],
            'featuresOffered' => array_column(is_array($view['features']) ? $view['features'] : [], 'key'),
            'pictures' => array_map(
                static fn (mixed $picture): array => is_array($picture) ? ['slot' => $picture['slot'] ?? null, 'current' => $picture['current'] ?? false] : [],
                is_array($view['pictures']) ? $view['pictures'] : [],
            ),
            'fonts' => $view['fonts'],
            'notExpressible' => $view['notExpressible'],
            'notes' => $view['notes'],
            'applied' => $view['applied'],
        ];
    }

    /**
     * @param array<array-key, mixed> $features
     * @return array<string, mixed>
     */
    private function applyCalendar(User $user, AiScan $scan, array $features): array
    {
        $calendar = $this->designerCalendar($scan);
        $result = $this->dispatch(new AcceptCalendarScan($scan->id, $user->id, array_values(array_filter($features, is_string(...)))));
        assert(is_array($result));

        return [
            'scanId' => $scan->id->toString(),
            'status' => AiScanStatus::Accepted->value,
            'calendarId' => $calendar->id->toString(),
            'calendarUrl' => $this->urls->calendar($calendar->id->toString()),
            'revision' => $result['revision'] ?? null,
            'features' => $result['features'] ?? [],
            'unfilledPictures' => $result['unfilledPictures'] ?? 0,
            'restoreUntil' => $result['restoreUntil'] ?? null,
            'created' => $result['created'] ?? false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function restore(User $user, AiScan $scan, bool $confirm): array
    {
        if ($scan->kind !== AiScanKind::Calendar) {
            throw self::refused(AiScanRefusal::WrongState, 'restore applies to calendar scans only.');
        }

        $calendar = $this->designerCalendar($scan);

        try {
            $result = $this->dispatch(new RestoreCalendarScan($scan->id, $user->id, $confirm));
        } catch (AiScanRefused $refused) {
            if ($refused->refusal === AiScanRefusal::LayoutChanged) {
                throw self::refused(AiScanRefusal::LayoutChanged, 'the layout or the switches of the calendar were changed after the scan was applied — restoring would lose those edits. Nothing was changed; ask the user, then repeat with confirm: true.');
            }

            throw $refused;
        }

        assert(is_array($result));

        return [
            'scanId' => $scan->id->toString(),
            'calendarId' => $calendar->id->toString(),
            'calendarUrl' => $this->urls->calendar($calendar->id->toString()),
            'revision' => $result['revision'] ?? null,
            'restored' => $result['restored'] ?? false,
        ];
    }

    /** The scan's calendar, when the caller is its designer (the web's `calendar_edit`). */
    private function designerCalendar(AiScan $scan): Calendar
    {
        $calendar = $scan->calendar;

        if ($calendar === null) {
            throw self::refused(AiScanRefusal::WrongState, 'the calendar this scan was made for no longer exists.');
        }

        if (!$this->security->isGranted(CalendarVoter::EDIT, $calendar)) {
            throw new ToolCallException('designer_only: only the designer of the calendar (an editor of its project) can apply or restore a scanned layout. Nothing was changed.');
        }

        return $calendar;
    }

    /**
     * @return array<string, mixed>
     */
    private function list(User $user, null|string $projectId, null|string $status): array
    {
        $project = $this->project($projectId);

        if (!$this->access->canSeeProjectScans($user, $project)) {
            throw self::refused(AiScanRefusal::NotEnabled, 'only the people who can edit a project see its scans.');
        }

        if ($status !== null && AiScanStatus::tryFrom($status) === null) {
            throw new ToolCallException('status must be one of: ' . implode(', ', array_map(static fn (AiScanStatus $case): string => $case->value, AiScanStatus::cases())) . '.');
        }

        $scans = [];

        foreach ($this->lists->history($project, $user) as $row) {
            if ($status !== null && $row['status'] !== $status) {
                continue;
            }

            $scans[] = [
                'scanId' => $row['id'],
                'kind' => $row['kind'],
                'calendarId' => $row['calendarId'],
                'status' => $row['status'],
                'name' => $row['name'],
                'fileName' => $row['fileName'],
                'channel' => $row['channel'],
                'requestedBy' => $row['requestedBy'],
                'createdAt' => $row['createdAt'],
                'expiresAt' => $row['expiresAt'],
                'unfinished' => $row['unfinished'],
                'errorCode' => $row['code'],
                'retriesLeft' => $row['retriesLeft'],
                'templateId' => $row['resultTemplateId'],
                'variantId' => $row['resultVariantId'],
            ];
        }

        return ['projectId' => $project->id->toString(), 'scans' => $scans];
    }

    /**
     * @return array<string, mixed>
     */
    private function cancel(User $user, AiScan $scan): array
    {
        // Counted when it was running: the work was already paid for.
        $running = $this->scans->current($scan)->status === AiScanStatus::Running;
        $this->dispatch(new CancelAiScan($scan->id, $user->id));
        $after = $this->scanRepository->get($scan->id);

        return [
            'scanId' => $scan->id->toString(),
            'status' => $after->status->value,
            'counted' => $running && $this->credits->wasSettled($scan->id->toString(), $after->try),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function restart(User $user, AiScan $scan, string $action): array
    {
        if (!$this->access->isEntitled($user, $scan->project)) {
            throw self::refused(AiScanRefusal::NotEnabled);
        }

        $this->dispatch($action === 'retry' ? new RetryAiScan($scan->id, $user->id) : new ContinueAiScan($scan->id, $user->id));
        $after = $this->scanRepository->get($scan->id);
        $view = $this->scans->view($after);

        return [
            'scanId' => $scan->id->toString(),
            'status' => $view['status'],
            'retriesLeft' => $view['retriesLeft'],
            'queuePosition' => $view['queuePosition'],
            'estimatedStartSeconds' => $view['estimatedStartSeconds'],
            'estimatedFinishSeconds' => $view['estimatedFinishSeconds'],
            'pollAfterSeconds' => self::POLL_SECONDS,
        ];
    }

    // ---- lookups -----------------------------------------------------------

    private function project(null|string $projectId): Project
    {
        $missing = new ToolCallException(sprintf('No project with id %s exists, or this account cannot see it.', $projectId ?? '(none given)'));

        if ($projectId === null || !Uuid::isValid($projectId)) {
            throw $missing;
        }

        try {
            $project = $this->projectRepository->get(Uuid::fromString($projectId));
        } catch (ProjectNotFound) {
            throw $missing;
        }

        if (!$this->security->isGranted(ProjectVoter::VIEW, $project)) {
            throw $missing;
        }

        return $project;
    }

    /**
     * The scan, when the caller may USE it; "not found" otherwise — the tool
     * does not say whether somebody else's scan exists.
     */
    private function scan(User $user, null|string $scanId): AiScan
    {
        $missing = new ToolCallException(sprintf('No scan with id %s exists, or this account cannot use it.', $scanId ?? '(none given)'));

        if ($scanId === null || !Uuid::isValid($scanId)) {
            throw $missing;
        }

        $scan = $this->scanRepository->find(Uuid::fromString($scanId));

        if ($scan === null || !$this->access->canUse($user, $scan)) {
            throw $missing;
        }

        return $scan;
    }

    /**
     * @return array{string, string} bytes, file name
     */
    private function galleryBytes(Project $project, string $imageId): array
    {
        $file = Uuid::isValid($imageId) ? $this->files->find(Uuid::fromString($imageId)) : null;

        if ($file === null || !$file->project->id->equals($project->id) || $file->isTrashed() || !$file->source->isGalleryPicture()) {
            throw new ToolCallException(sprintf('No gallery picture %s in this project — use list_gallery. Nothing was started.', $imageId));
        }

        try {
            return [$this->filesystem->read($file->path), $file->originalName ?? basename($file->path)];
        } catch (FilesystemException) {
            throw new ToolCallException('That gallery picture could not be read from storage. Nothing was started; try again.');
        }
    }

    private static function decode(string $imageBase64): string
    {
        $payload = preg_replace('#^data:[^;,]*(;[^,]*)?,#', '', trim($imageBase64)) ?? '';
        $bytes = base64_decode(preg_replace('/\s+/', '', $payload) ?? '', true);

        if ($bytes === false || $bytes === '') {
            throw self::refused(AiScanRefusal::UnsupportedImage, 'imageBase64 is not valid base64. Nothing was started.');
        }

        if (strlen($bytes) > self::MAX_INLINE_BYTES) {
            throw self::refused(AiScanRefusal::UnsupportedImage, sprintf('an inline picture may be at most about %d bytes (one MCP request body is limited); upload it with upload_image and pass imageId instead. Nothing was started.', self::MAX_INLINE_BYTES));
        }

        return $bytes;
    }

    private static function requestId(null|string $requestId): string
    {
        if ($requestId === null || $requestId === '') {
            return bin2hex(random_bytes(12));
        }

        if (preg_match('/^[A-Za-z0-9_.:\-]{1,64}$/', $requestId) !== 1) {
            throw new ToolCallException('requestId must be 1–64 characters of letters, digits, "_", ".", ":" or "-". Nothing was started.');
        }

        return $requestId;
    }

    /**
     * @param null|array<array-key, mixed> $format
     * @return array<string, int|string>
     */
    private static function options(null|array $format): array
    {
        if ($format === null || $format === []) {
            return [];
        }

        $unit = is_string($format['unit'] ?? null) ? DimensionUnit::tryFrom($format['unit']) : null;
        $width = $format['width'] ?? null;
        $height = $format['height'] ?? null;

        if ($unit === null || !is_numeric($width) || !is_numeric($height) || (float) $width <= 0 || (float) $height <= 0) {
            throw new ToolCallException('format must be {"unit": "px"|"mm"|"cm", "width": n, "height": n} with positive numbers. Nothing was started.');
        }

        return ['targetUnit' => $unit->value, 'targetWidth' => (string) round((float) $width, 2), 'targetHeight' => (string) round((float) $height, 2)];
    }

    private static function sentence(AiScan $scan): string
    {
        if ($scan->status === AiScanStatus::Refused) {
            return 'The AI provider refused to process this picture. It was not counted.';
        }

        return match ($scan->errorCode) {
            'photo_of_print' => 'This looks like a photo of a print: sizes and colours would only be approximate. Scan an export or a screenshot instead, or call "continue" once to scan it anyway. Not counted.',
            'blurry_or_small' => 'The picture is too blurry or small to read its texts. Use a sharper or larger version. Not counted.',
            'not_expected_kind' => 'No design was found in the picture — it is empty, or only a photograph without texts and shapes. Not counted.',
            'several_designs' => 'The picture holds several designs; one picture makes one template. Crop it to one design. Not counted.',
            'unusable_input' => 'The file is damaged or in an unusual format. Save it again as PNG or JPG. Not counted.',
            'provider_unavailable' => 'The AI service did not answer. The picture is stored — "retry". Not counted.',
            'renderer_unavailable' => 'The preview could not be rendered. The picture is stored — "retry". Not counted.',
            'timeout' => 'The scan took much longer than usual and was stopped. A simpler or smaller picture helps. Not counted.',
            'queue_timeout' => 'The scan waited in the queue for over an hour and was stopped. "retry". Not counted.',
            'build' => 'The picture was read, but the proposal could not be put together. Not counted.',
            default => 'Something went wrong on the server side. The picture is stored — "retry". Not counted.',
        };
    }

    // ---- plumbing ----------------------------------------------------------

    /**
     * @throws AiScanRefused
     */
    private function dispatch(object $message): mixed
    {
        try {
            return $this->bus->dispatch($message)->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $failed) {
            $cause = $failed->getPrevious();

            throw $cause instanceof AiScanRefused ? $cause : $failed;
        }
    }

    /**
     * @param array<string, int|string> $params
     */
    private static function refused(AiScanRefusal $refusal, null|string $detail = null, array $params = []): ToolCallException
    {
        $detail ??= match ($refusal) {
            AiScanRefusal::NotEnabled => 'AI scan is not switched on for this project or account. It is a paid feature WBoost enables per project on request (the request is sent from the web app).',
            AiScanRefusal::ConsentRequired => 'the user has not agreed to the current data-processing text. They must open "Šablona z obrázku" in the wboost web app once and agree; an agent cannot agree for them.',
            AiScanRefusal::LimitReached => 'the project used up its scans for this month.',
            AiScanRefusal::DailyStartsReached => 'the daily number of scan starts for this user or project is used up; continue tomorrow.',
            AiScanRefusal::ProjectQueueFull => 'the project already has 4 scans in progress; wait for one to finish.',
            AiScanRefusal::QueueFull => 'the queue is full right now; try again in a while.',
            AiScanRefusal::TemporarilyUnavailable => 'AI scan is temporarily unavailable (paused, or the scanner is away); try later.',
            AiScanRefusal::ProjectPaused => 'the AI refused three pictures of this project today; scans can be started again tomorrow.',
            AiScanRefusal::NoFonts => 'the project has no font, and texts cannot be created without one; upload a font (upload_font) first.',
            AiScanRefusal::UnsupportedImage => 'the picture must be a JPG, PNG, WebP or HEIC (or a PDF — its first page is scanned) of at most 10 MB, each side at most 8000 px, at most 36 megapixels, the shorter side at least 600 px (a calendar page: at least 640 × 280 px).',
            AiScanRefusal::SourceUnscannable => 'this picture already failed twice; use another one.',
            AiScanRefusal::NoRetriesLeft => 'both retries of this upload are used up.',
            AiScanRefusal::WrongState => 'the scan is not in a state this action applies to; read "status".',
            AiScanRefusal::StaleReferences => 'a font or a picture the proposal uses no longer exists in the project; read "result" again — its decisions list what to fix. Nothing was created.',
            AiScanRefusal::RendererBusy => 'the renderer is busy; nothing was created — call again in a few seconds.',
            AiScanRefusal::InvalidFix => 'a fix names something the proposal does not have, or a value it cannot take.',
            default => 'the action was refused.',
        };

        $extra = $params === [] ? '' : ' ' . json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return new ToolCallException(sprintf('%s: %s%s', $refusal->value, $detail, $extra));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data): CallToolResult
    {
        return new CallToolResult([self::text($data)]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function text(array $data): TextContent
    {
        return new TextContent(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
