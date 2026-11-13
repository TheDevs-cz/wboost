<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan;

use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Entity\Project;
use WBoost\Web\Exceptions\CalendarModelUnavailable;
use WBoost\Web\Exceptions\CalendarNotFound;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Query\GetProjectPalette;
use WBoost\Web\Repository\CalendarRepository;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\AiScan\Calendar\CalendarCandidate;
use WBoost\Web\Services\AiScan\Calendar\CalendarScanProposal;
use WBoost\Web\Services\AiScan\Template\FontFacesRendererInterface;
use WBoost\Web\Services\AiScan\Template\OmittedOnLayers;
use WBoost\Web\Services\AiScan\Template\ScanRenderLimiter;
use WBoost\Web\Services\AiScan\Template\TemplateProposal;
use WBoost\Web\Value\AiScan\AiScanErrorCode;
use WBoost\Web\Value\AiScan\AiScanKind;
use WBoost\Web\Value\AiScan\AiScanLane;
use WBoost\Web\Value\AiScan\AiScanLimits;
use WBoost\Web\Value\AiScan\AiScanMode;
use WBoost\Web\Value\AiScan\AiScanNote;
use WBoost\Web\Value\AiScan\AiScanStage;
use WBoost\Web\Value\AiScan\AiScanStatus;
use WBoost\Web\Value\AiScan\AiScanStopReason;
use WBoost\Web\Value\AiScan\AiUsageFigures;
use WBoost\Web\Value\AiScan\ProviderState;
use WBoost\Web\Value\AiScan\ScannerApiCode;
use WBoost\Web\Value\Calendar\CalendarType;

/**
 * The scanner API (`/internal/scanner/*`) — everything behind the nine
 * controllers. The wire contract is written down in
 * docs/architecture/ai-scan.md § "Scanner API"; this class is its
 * implementation and the controllers only unpack the request.
 *
 * Rules that hold for every call:
 *
 * - The caller was authenticated by
 *   {@see \WBoost\Web\Services\Security\ScannerServiceAuthenticator} (source
 *   network + token). A per-scan call also proves it holds the scan: the
 *   **lease token** of its claim (header `X-Scan-Lease`) and the **run** it
 *   was given.
 * - An expected "no" is `{ok: false, code}` — never an exception, so it books
 *   nothing in Sentry. HTTP 409 = this run is over, abort; 410 = the scan is
 *   gone, abort; 200 = carry on.
 * - **The app never decodes what passes through**: pictures in, pictures out,
 *   as bytes.
 * - Nothing of a scan's content is logged: ids, codes and counts only.
 */
final readonly class ScannerApi
{
    public const string PROTOCOL_HEADER = 'X-Scanner-Protocol';
    public const string LEASE_HEADER = 'X-Scan-Lease';

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private AiScanRuntimeState $runtime,
        private AiScanDefinition $definition,
        private AiScanReaper $reaper,
        private AiScanTransitions $transitions,
        private AiScanSpend $spend,
        private RecordAiUsage $usage,
        private AiScanStorage $storage,
        private AiScanReportValidator $reportValidator,
        private TemplateProposal $templateProposal,
        private FontFacesRendererInterface $fontFaces,
        private ScanRenderLimiter $renderLimiter,
        private ProjectRepository $projectRepository,
        private GetFonts $getFonts,
        private GetProjectPalette $getProjectPalette,
        private CalendarCandidate $calendarCandidate,
        private CalendarRepository $calendarRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Every call starts here: the protocol version. A scanner speaking a
     * version this app does not accept (N and N − 1) is recorded as too old
     * and told so.
     */
    public function enter(Request $request, null|string $scannerVersion = null): null|JsonResponse
    {
        $version = $request->headers->get(self::PROTOCOL_HEADER);

        if ($version === null || !ctype_digit($version) || !$this->definition->acceptsProtocol((int) $version)) {
            $this->runtime->scannerTooOld(self::version($scannerVersion));

            return self::refuse(ScannerApiCode::ProtocolTooOld, ['protocolVersion' => $this->definition->protocolVersion()]);
        }

        $this->runtime->scannerSeen(self::version($scannerVersion));

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function body(Request $request): array
    {
        try {
            $body = json_decode($request->getContent(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        /** @var array<string, mixed> $body */
        $body = is_array($body) ? $body : [];

        return $body;
    }

    // ---- claim / tick ------------------------------------------------------

    /**
     * @param array<string, mixed> $body `{lane, scannerVersion, instruments, capacity}`
     */
    public function claim(array $body): JsonResponse
    {
        $lane = AiScanLane::tryFrom(is_string($body['lane'] ?? null) ? $body['lane'] : '');

        if ($lane === null) {
            return self::refuse(ScannerApiCode::InvalidRequest);
        }

        $this->reaper->run();

        $scannerVersion = self::version($body['scannerVersion'] ?? null);
        $instruments = self::strings($body['instruments'] ?? null, 50, 64);

        if (array_diff($this->definition->requiredInstruments(), $instruments) !== []) {
            $this->runtime->scannerTooOld($scannerVersion);

            return $this->noJob('scanner_too_old');
        }

        $runtime = $this->runtime->get();
        $now = $this->clock->now();

        if ($runtime->mode !== AiScanMode::On) {
            return $this->noJob($runtime->mode->value);
        }

        if ($runtime->providerState === ProviderState::Blocked) {
            return $this->noJob('provider_blocked');
        }

        if ($lane === AiScanLane::Build && $this->spend->totalCeilingReached($runtime, $now)) {
            return $this->noJob('spend_ceiling');
        }

        $capacity = is_int($body['capacity'] ?? null) ? max(1, $body['capacity']) : 1;
        $leaseToken = bin2hex(random_bytes(32));
        $hash = hash('sha256', $leaseToken);

        // A scanner builds only the kinds whose instruments it has: one that is
        // older than a kind keeps serving the others and never sees that
        // kind's scans (they wait for a scanner that can build them).
        $kinds = $this->definition->buildableKinds($instruments);
        // … and, of calendar scans, a 14-day calendar's only when it knows the
        // two-column layout (the variant's own instrument).
        $fortnight = $this->definition->canBuildVariant(AiScanKind::Calendar->value, CalendarType::Fortnight->value, $instruments);

        $claimed = $this->connection->transactional(function () use ($lane, $runtime, $now, $capacity, $hash, $scannerVersion, $kinds, $fortnight): null|array {
            if ($lane === AiScanLane::Intake) {
                $id = $this->connection->fetchOne(
                    "SELECT id FROM ai_scan WHERE status = 'checking' AND lease_expires_at IS NULL
                      ORDER BY waiting_since, id LIMIT 1 FOR UPDATE SKIP LOCKED",
                );

                if (!is_string($id)) {
                    return null;
                }

                $run = $this->transitions->claimIntake($id, $hash, $scannerVersion);

                return $run === null ? null : ['id' => $id, 'run' => $run];
            }

            // One build claim at a time: the lock on the runtime row is what
            // makes `max_running` hold when two workers ask together.
            $this->connection->executeQuery('SELECT id FROM ai_scan_runtime WHERE id = 1 FOR UPDATE');

            $running = $this->connection->fetchOne("SELECT COUNT(*) FROM ai_scan WHERE status = 'running'");

            if ((is_numeric($running) ? (int) $running : 0) >= min($runtime->maxRunning, $capacity)) {
                return null;
            }

            if ($kinds === []) {
                return null;
            }

            /** @var list<array{id: string, project_id: string}> $queued */
            $queued = $this->connection->fetchAllAssociative(
                "SELECT id, project_id FROM ai_scan WHERE status = 'queued' AND lease_expires_at IS NULL AND kind IN (:kinds)"
                . ($fortnight ? '' : " AND NOT EXISTS (SELECT 1 FROM calendar WHERE calendar.id = ai_scan.calendar_id AND calendar.type = 'fortnight')")
                . ' ORDER BY waiting_since, id LIMIT 20 FOR UPDATE OF ai_scan SKIP LOCKED',
                ['kinds' => $kinds],
                ['kinds' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );

            foreach ($queued as $candidate) {
                // A project at its own ceiling waits; the next project's scan goes first.
                if (!$this->spend->allowsAnotherRun($runtime, $candidate['project_id'], $now)) {
                    continue;
                }

                $run = $this->transitions->claimBuild($candidate['id'], $hash, $scannerVersion, $this->definition->version());

                if ($run !== null) {
                    return ['id' => $candidate['id'], 'run' => $run];
                }
            }

            return null;
        });

        if ($claimed === null) {
            return $this->noJob(null);
        }

        $row = $this->row($claimed['id']);

        if ($row === null) {
            return $this->noJob(null);
        }

        if ($lane === AiScanLane::Build) {
            // A new run renders its own candidates: what an earlier run of
            // this scan left (a restart, a retry) is nobody's any more.
            $this->storage->dropCandidates(AiScanStorage::directory(self::string($row['project_id']), $claimed['id'], self::string($row['storage_secret'])));
        }

        $this->logger->info('AI scan claimed.', ['scan' => $claimed['id'], 'lane' => $lane->value, 'run' => $claimed['run']]);

        return new JsonResponse([
            'ok' => true,
            'mode' => $runtime->mode->value,
            'job' => $this->job($row, $lane, $claimed['run'], $leaseToken),
        ]);
    }

    /**
     * Every 30 s and at start: runs the reaper, takes the provider probe's
     * result.
     *
     * @param array<string, mixed> $body `{scannerVersion, provider?: {state, error?}}`
     */
    public function tick(array $body): JsonResponse
    {
        $reaped = $this->reaper->run();
        $provider = $body['provider'] ?? null;

        if (is_array($provider)) {
            $state = ProviderState::tryFrom(is_string($provider['state'] ?? null) ? $provider['state'] : '');

            if ($state !== null) {
                $this->runtime->providerReported($state, is_string($provider['error'] ?? null) ? $provider['error'] : null);
            }
        }

        $runtime = $this->runtime->get();

        return new JsonResponse([
            'ok' => true,
            'mode' => $runtime->mode->value,
            'providerState' => $runtime->providerState->value,
            'maxRunning' => $runtime->maxRunning,
            'reaped' => $reaped,
            'protocolVersion' => $this->definition->protocolVersion(),
        ]);
    }

    // ---- per-scan calls ----------------------------------------------------

    public function source(string $id, mixed $run, null|string $lease): Response
    {
        $held = $this->held($id, $run, $lease);

        if ($held instanceof JsonResponse) {
            return $held;
        }

        if (!$held['live']) {
            return self::refuse(ScannerApiCode::LeaseLost);
        }

        $meta = self::decode($held['row']['source_meta']);
        $intake = $held['lane'] === AiScanLane::Intake;
        $file = $intake ? AiScanStorage::originalName(self::string($meta['ext'] ?? null)) : AiScanStorage::WORKING;
        $bytes = $this->storage->read($held['directory'], $file);

        if ($bytes === null) {
            return self::refuse(ScannerApiCode::MissingArtifact);
        }

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => $intake ? self::string($meta['contentType'] ?? null, 'application/octet-stream') : 'image/webp',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * The intake stores what it made: the working copy and the preview.
     */
    public function artifact(string $id, string $which, mixed $run, null|string $lease, string $bytes): JsonResponse
    {
        $held = $this->held($id, $run, $lease);

        if ($held instanceof JsonResponse) {
            return $held;
        }

        if (!$held['live'] || $held['lane'] !== AiScanLane::Intake) {
            return self::refuse(ScannerApiCode::LeaseLost);
        }

        $file = match ($which) {
            'working' => AiScanStorage::WORKING,
            'preview' => AiScanStorage::PREVIEW,
            default => null,
        };

        if ($file === null || $bytes === '' || strlen($bytes) > AiScanLimits::ARTIFACT_MAX_BYTES) {
            return self::refuse(ScannerApiCode::InvalidRequest);
        }

        try {
            $this->storage->write($held['directory'], $file, $bytes);
        } catch (FilesystemException) {
            // Storage is down: a 503 the scanner retries inside its tolerance.
            return new JsonResponse(['ok' => false, 'code' => 'storage_unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new JsonResponse(['ok' => true, 'bytes' => strlen($bytes)]);
    }

    /**
     * The timer heartbeat: extends the lease, upserts usage, carries the
     * stage — and tells the scanner to stop when the scan was cancelled.
     *
     * @param array<string, mixed> $body `{run, seq, stage?, note?, noteParams?, round?, usage, resources}`
     */
    public function beat(string $id, array $body, null|string $lease): JsonResponse
    {
        $held = $this->held($id, $body['run'] ?? null, $lease);

        if ($held instanceof JsonResponse) {
            $this->recordReplacedRun($id, $body['run'] ?? null, $lease, $body['usage'] ?? null);

            return $held;
        }

        $lane = $held['lane'];
        $this->usage->record($id, $held['run'], $lane->usageStep(), AiUsageFigures::fromPayload($body['usage'] ?? null));

        if (!$held['live']) {
            return $this->afterTheRun($held['status']);
        }

        $seq = is_int($body['seq'] ?? null) ? $body['seq'] : 0;
        $stage = AiScanStage::tryFrom(is_string($body['stage'] ?? null) ? $body['stage'] : '');
        $note = AiScanNote::tryFrom(is_string($body['note'] ?? null) ? $body['note'] : '');
        $leaseSeconds = $lane === AiScanLane::Intake ? AiScanLimits::INTAKE_LEASE_SECONDS : AiScanLimits::LEASE_SECONDS;
        $now = $this->clock->now();
        $expires = $now->modify(sprintf('+%d seconds', $leaseSeconds));

        $set = ['lease_expires_at = :lease', 'beat_seq = :seq'];
        $params = [
            'lease' => AiScanTime::sql($expires),
            'seq' => $seq,
            'id' => $id,
            'hash' => $held['hash'],
            'run' => $held['run'],
        ];

        if ($stage !== null) {
            $set[] = 'stage_started_at = CASE WHEN stage IS DISTINCT FROM :stage THEN CAST(:now AS TIMESTAMP) ELSE stage_started_at END';
            $set[] = 'stage = :stage';
            $params['stage'] = $stage->value;
            $params['now'] = AiScanTime::sql($now);
        }

        if (array_key_exists('note', $body)) {
            $set[] = 'note = :note';
            $set[] = 'note_params = CAST(:noteParams AS JSONB)';
            $params['note'] = $note?->value;
            $params['noteParams'] = $note === null || $note->paramNames() === [] ? null : json_encode($note->params($body['noteParams'] ?? null));
        }

        // The stage log (durations and the note each stage ended with) —
        // what the waiting screen shows of the stages nobody watched.
        $log = "COALESCE(stage_log, '{}'::jsonb)";

        if ($stage !== null) {
            $log = sprintf(
                "jsonb_set(%s, ARRAY[CAST(:stage AS TEXT)], COALESCE(stage_log -> CAST(:stage AS TEXT), jsonb_build_object('at', CAST(:epoch AS BIGINT))))",
                $log,
            );
            $params['epoch'] = $now->getTimestamp();
        }

        if ($note !== null) {
            // A note about what was read belongs to the reading, whichever
            // stage the same beat already announces.
            $owner = in_array($note, [AiScanNote::TextsFound, AiScanNote::RowsFound], true)
                ? "'read'"
                : ($stage !== null ? 'CAST(:stage AS TEXT)' : 'CAST(stage AS TEXT)');
            $log = sprintf(
                "(SELECT CASE WHEN %2\$s IS NULL THEN l.v ELSE jsonb_set(l.v, ARRAY[%2\$s], COALESCE(l.v -> %2\$s, jsonb_build_object('at', CAST(:epoch AS BIGINT))) || CAST(:notePatch AS JSONB)) END FROM (SELECT %1\$s AS v) l)",
                $log,
                $owner,
            );
            $params['epoch'] = $now->getTimestamp();
            $params['notePatch'] = json_encode(['note' => $note->value, 'params' => $note->paramNames() === [] ? null : $note->params($body['noteParams'] ?? null)]);
        }

        if ($stage !== null || $note !== null) {
            $set[] = 'stage_log = ' . $log;
        }

        $textBoxes = self::textBoxes($body['textBoxes'] ?? null);

        if ($textBoxes !== null) {
            $set[] = 'text_boxes = CAST(:textBoxes AS JSONB)';
            $params['textBoxes'] = json_encode($textBoxes);
        }

        if (is_int($body['round'] ?? null)) {
            $set[] = 'round = :round';
            $params['round'] = max(0, min($body['round'], 9));
        }

        $resources = self::resources($body['resources'] ?? null);

        if ($resources !== null) {
            $set[] = 'resources = CAST(:resources AS JSONB)';
            $params['resources'] = json_encode($resources);
        }

        // Conditional like every other write: only the run that still holds
        // the scan extends its lease, and an older `seq` changes nothing.
        $this->connection->executeStatement(
            sprintf(
                "UPDATE ai_scan SET %s WHERE id = :id AND status = '%s' AND %s = :run AND lease_token_hash = :hash
                    AND lease_expires_at IS NOT NULL AND beat_seq < :seq",
                implode(', ', $set),
                $lane->liveStatus()->value,
                $lane === AiScanLane::Intake ? 'intake_tries' : 'run',
            ),
            $params,
        );

        // Whatever the update did (applied, a repeated seq, or the row just
        // left): answer from the row as it is now.
        $after = $this->held($id, $held['run'], $lease);

        if ($after instanceof JsonResponse) {
            return $after;
        }

        if (!$after['live']) {
            return $this->afterTheRun($after['status']);
        }

        return new JsonResponse([
            'ok' => true,
            'cancel' => false,
            'mode' => $this->runtime->get()->mode->value,
            'leaseExpiresAt' => AiScanTime::fromSql(self::nullableString($after['row']['lease_expires_at']))?->format(DATE_ATOM),
        ]);
    }

    /**
     * Validate + one render of a candidate design. Idempotent by
     * `(run, seq)`: a repeated call returns the stored candidate.
     *
     * @param array<string, mixed> $body `{run, seq, design}`
     */
    public function render(string $id, array $body, null|string $lease): JsonResponse
    {
        $held = $this->held($id, $body['run'] ?? null, $lease);

        if ($held instanceof JsonResponse) {
            return $held;
        }

        if (!$held['live'] || $held['lane'] !== AiScanLane::Build) {
            return self::refuse(ScannerApiCode::LeaseLost);
        }

        $calendar = $held['row']['kind'] === AiScanKind::Calendar->value;

        $seq = $body['seq'] ?? null;

        if (!is_int($seq) || !array_key_exists($calendar ? 'commands' : 'design', $body)) {
            return self::refuse(ScannerApiCode::InvalidRequest);
        }

        $candidates = self::candidates($held['row']['candidates']);

        foreach ($candidates as $candidate) {
            if (($candidate['seq'] ?? null) === $seq) {
                $bytes = $this->storage->read($held['directory'], AiScanStorage::candidateName(self::int($candidate['n'] ?? null)));

                if ($bytes !== null) {
                    return self::rendered($candidate, $bytes, $calendar ? CalendarScanProposal::answerOf($candidate) : []);
                }
            }
        }

        if (count($candidates) >= $this->definition->maxCandidates()) {
            return self::refuse(ScannerApiCode::CandidateLimit, ['candidates' => count($candidates)]);
        }

        try {
            $project = $this->projectRepository->get(Uuid::fromString(self::string($held['row']['project_id'])));
        } catch (ProjectNotFound) {
            return self::refuse(ScannerApiCode::Gone);
        }

        $slot = $this->renderLimiter->acquire();

        if ($slot === null) {
            return self::refuse(ScannerApiCode::RendererBusy);
        }

        try {
            if ($calendar) {
                $calendarId = self::nullableString($held['row']['calendar_id']);
                $result = $this->calendarCandidate->render(
                    $this->calendarRepository->get(Uuid::fromString($calendarId ?? '')),
                    $body['commands'],
                );
            } else {
                $result = $this->templateProposal->candidate($project, $body['design']) + ['design' => $body['design'], 'answer' => []];
            }
        } catch (TemplateRenderUnavailable|CalendarModelUnavailable) {
            return self::refuse(ScannerApiCode::RendererUnavailable);
        } catch (CalendarNotFound|\InvalidArgumentException) {
            // The calendar the scan was made for is gone: nothing to build on.
            return self::refuse(ScannerApiCode::Gone);
        } finally {
            $this->renderLimiter->release($slot);
        }

        if ($result['blocked'] || $result['bytes'] === null) {
            // A rejected design is an answer, not an error: nothing rendered,
            // nothing stored, the issues say why.
            return new JsonResponse(['ok' => true, 'candidate' => null, 'issues' => $result['issues']]);
        }

        $number = count($candidates) + 1;

        try {
            $this->storage->write($held['directory'], AiScanStorage::candidateName($number), $result['bytes']);
        } catch (FilesystemException) {
            return new JsonResponse(['ok' => false, 'code' => 'storage_unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $entry = [
            'n' => $number,
            'seq' => $seq,
            'width' => $result['width'],
            'height' => $result['height'],
            'issues' => $result['issues'],
            'design' => $result['design'],
        ];

        $stored = $this->connection->executeStatement(
            "UPDATE ai_scan
                SET candidates = candidates || CAST(:entry AS JSONB), preview_seq = preview_seq + 1, renders = renders + 1
              WHERE id = :id AND status = 'running' AND run = :run AND lease_token_hash = :hash
                AND jsonb_array_length(candidates) = :count",
            [
                'entry' => json_encode([$entry], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'id' => $id,
                'run' => $held['run'],
                'hash' => $held['hash'],
                'count' => count($candidates),
            ],
        );

        if ($stored !== 1) {
            // The scan left `running` (or a twin call won) while this rendered.
            return self::refuse(ScannerApiCode::LeaseLost);
        }

        return self::rendered($entry, $result['bytes'], $result['answer']);
    }

    /**
     * One render of the lines in each face; the bytes go back untouched.
     * Stores nothing, so repeating it is harmless.
     *
     * @param array<string, mixed> $body `{run, seq, lines, faces?, size?}`
     */
    public function faces(string $id, array $body, null|string $lease): JsonResponse
    {
        $held = $this->held($id, $body['run'] ?? null, $lease);

        if ($held instanceof JsonResponse) {
            return $held;
        }

        if (!$held['live'] || $held['lane'] !== AiScanLane::Build) {
            return self::refuse(ScannerApiCode::LeaseLost);
        }

        $lines = self::strings($body['lines'] ?? null, FontFacesRendererInterface::MAX_LINES, FontFacesRendererInterface::MAX_LINE_LENGTH);
        $faces = array_key_exists('faces', $body) && $body['faces'] !== null
            ? self::strings($body['faces'], FontFacesRendererInterface::MAX_FACES, 200)
            : null;
        $size = is_int($body['size'] ?? null) ? $body['size'] : FontFacesRendererInterface::DEFAULT_SIZE;

        if ($lines === [] || $faces === [] || $size < FontFacesRendererInterface::MIN_SIZE || $size > FontFacesRendererInterface::MAX_SIZE) {
            return self::refuse(ScannerApiCode::InvalidRequest);
        }

        $slot = $this->renderLimiter->acquire();

        if ($slot === null) {
            return self::refuse(ScannerApiCode::RendererBusy);
        }

        try {
            $result = $this->fontFaces->render(Uuid::fromString(self::string($held['row']['project_id'])), $lines, $faces, $size);
        } catch (TemplateRenderUnavailable) {
            return self::refuse(ScannerApiCode::RendererUnavailable);
        } finally {
            $this->renderLimiter->release($slot);
        }

        return new JsonResponse([
            'ok' => true,
            'width' => $result['width'],
            'height' => $result['height'],
            'cells' => $result['cells'],
            'image' => $result['bytes'] === '' ? null : ['contentType' => $result['contentType'], 'base64' => base64_encode($result['bytes'])],
        ]);
    }

    /**
     * One database write: the outcome, the report, the usage and the number
     * of the kept candidate (its render was stored by `render`). Idempotent
     * per `(scan, run)`.
     *
     * @param array<string, mixed> $body intake: `{run, outcome: passed|stopped, reason?, preCheck?, sourceMeta?, usage}`;
     *        build: `{run, outcome: proposed|failed|refused, candidate?, report?, boxes?, error?, usage}`
     */
    public function finish(string $id, array $body, null|string $lease): JsonResponse
    {
        $held = $this->held($id, $body['run'] ?? null, $lease);

        if ($held instanceof JsonResponse) {
            $this->recordReplacedRun($id, $body['run'] ?? null, $lease, $body['usage'] ?? null);

            return $held;
        }

        $lane = $held['lane'];
        $run = $held['run'];
        $hash = $held['hash'];
        $outcome = is_string($body['outcome'] ?? null) ? $body['outcome'] : '';

        $this->usage->record($id, $run, $lane->usageStep(), AiUsageFigures::fromPayload($body['usage'] ?? null));

        if ($lane === AiScanLane::Intake) {
            if (!$held['live']) {
                // An intake that no longer holds the scan (its lease was lost,
                // or it already finished): usage only.
                return $this->finished($id, false, true);
            }

            $preCheck = self::flat($body['preCheck'] ?? null);

            $done = match ($outcome) {
                'passed' => $this->storage->exists($held['directory'], AiScanStorage::WORKING)
                    ? $this->transitions->intakePassed($id, $run, $hash, $preCheck, self::sourceFacts($body['sourceMeta'] ?? null))
                    : null,
                'stopped' => ($reason = AiScanStopReason::tryFrom(is_string($body['reason'] ?? null) ? $body['reason'] : '')) !== null
                    ? $this->transitions->intakeStopped($id, $run, $hash, $reason, $preCheck)
                    : self::refuse(ScannerApiCode::InvalidRequest),
                // Rule 3c: the pre-check could not be made / the picture was refused.
                'failed' => ($body['error'] ?? null) === AiScanErrorCode::ProviderUnavailable->value
                    ? $this->transitions->intakeFailed($id, $run, $hash)
                    : self::refuse(ScannerApiCode::InvalidRequest),
                'refused' => $this->transitions->intakeRefused($id, $run, $hash),
                default => self::refuse(ScannerApiCode::InvalidRequest),
            };

            if ($done instanceof JsonResponse) {
                return $done;
            }

            if ($done === null) {
                return self::refuse(ScannerApiCode::MissingArtifact);
            }

            return $this->finished($id, $done, true);
        }

        $done = match ($outcome) {
            'proposed' => $this->proposed($id, $held, $body),
            'failed' => ($error = AiScanErrorCode::tryFrom(is_string($body['error'] ?? null) ? $body['error'] : '')) !== null
                    && in_array($error, AiScanErrorCode::reportable(), true)
                ? $this->transitions->finishFailed($id, $run, $hash, $error)
                : self::refuse(ScannerApiCode::InvalidRequest),
            'refused' => $this->transitions->finishRefused($id, $run, $hash),
            default => self::refuse(ScannerApiCode::InvalidRequest),
        };

        if ($done instanceof JsonResponse) {
            return $done;
        }

        if ($done) {
            // The run is over: only the kept candidate — the proposal's
            // picture — stays; a run that delivered nothing keeps none.
            $kept = $this->connection->fetchOne("SELECT kept_candidate FROM ai_scan WHERE id = :id AND status = 'ready'", ['id' => $id]);
            $this->storage->dropCandidates($held['directory'], is_numeric($kept) ? (int) $kept : null);
        }

        return $this->finished($id, $done, true);
    }

    /**
     * The scanner is shutting down (or the provider is blocked): the scan
     * goes back, the automatic restart is not consumed.
     *
     * @param array<string, mixed> $body `{run, usage, reason?: shutdown|provider_blocked, providerError?}`
     */
    public function release(string $id, array $body, null|string $lease): JsonResponse
    {
        $held = $this->held($id, $body['run'] ?? null, $lease);

        if ($held instanceof JsonResponse) {
            $this->recordReplacedRun($id, $body['run'] ?? null, $lease, $body['usage'] ?? null);

            return $held;
        }

        $this->usage->record($id, $held['run'], $held['lane']->usageStep(), AiUsageFigures::fromPayload($body['usage'] ?? null));

        if (($body['reason'] ?? null) === 'provider_blocked') {
            $this->runtime->providerReported(ProviderState::Blocked, is_string($body['providerError'] ?? null) ? $body['providerError'] : null);
        }

        $released = $held['lane'] === AiScanLane::Intake
            ? $this->transitions->releaseIntake($id, $held['run'], $held['hash'])
            : $this->transitions->releaseBuild($id, $held['run'], $held['hash']);

        return $this->finished($id, $released);
    }

    // ---- internals ---------------------------------------------------------

    /**
     * @param array{row: array<string, mixed>, lane: AiScanLane, run: int, hash: string, live: bool, status: AiScanStatus, directory: string} $held
     * @param array<string, mixed> $body
     */
    private function proposed(string $id, array $held, array $body): bool
    {
        $kind = AiScanKind::from(self::string($held['row']['kind']));
        $report = $body['report'] ?? null;
        $problems = $this->reportValidator->validate($kind, $report);
        $kept = is_int($body['candidate'] ?? null) ? $body['candidate'] : (is_array($report) && is_int($report['candidate'] ?? null) ? $report['candidate'] : null);
        $candidate = null;

        foreach (self::candidates($held['row']['candidates']) as $entry) {
            if (($entry['n'] ?? null) === $kept) {
                $candidate = $entry;
            }
        }

        if ($problems !== [] || $candidate === null || !is_array($report)) {
            // An inconsistent report (or a candidate that was never rendered
            // here) is a failed build, not a proposal.
            $this->logger->warning('AI scan finish refused: inconsistent report.', [
                'scan' => $id,
                'run' => $held['run'],
                'problems' => array_slice($problems, 0, 20),
                'candidateKnown' => $candidate !== null,
            ]);

            return $this->transitions->finishFailed($id, $held['run'], $held['hash'], AiScanErrorCode::Build);
        }

        /** @var array<string, mixed> $report */
        if ($kind === AiScanKind::Template) {
            // A "left-out thing" lying on layers the scan built is never stored, so never offered.
            $report = OmittedOnLayers::without($report, is_array($body['boxes'] ?? null) ? $body['boxes'] : []);
        }

        return $this->transitions->finishProposed(
            $id,
            $held['run'],
            $held['hash'],
            self::int($candidate['n'] ?? null),
            $kind === AiScanKind::Calendar
                ? CalendarScanProposal::proposal($candidate)
                : TemplateProposal::proposal($candidate, self::measured($body['boxes'] ?? null)),
            $report,
            ($report['unfinished'] ?? false) === true,
        );
    }

    /**
     * The answer to finish / release: the state the scan is in now.
     *
     * A `finish` that did not apply — the scan was cancelled, interrupted,
     * already finished (a repeat), or failed by the reaper — recorded its
     * usage and is answered `stale_run` (409) with that state: the run is
     * over either way. A `release` that did not apply is simply `applied:
     * false`.
     */
    private function finished(string $id, bool $applied, bool $isFinish = false): JsonResponse
    {
        $row = $this->row($id);

        if ($row === null) {
            return self::refuse(ScannerApiCode::Gone);
        }

        if ($isFinish && !$applied) {
            return self::refuse(ScannerApiCode::StaleRun, array_filter([
                'status' => self::string($row['status']),
                'errorCode' => self::string($row['error_code']),
            ], static fn (string $value): bool => $value !== ''));
        }

        return new JsonResponse([
            'ok' => true,
            'applied' => $applied,
            'status' => $row['status'],
            'code' => $row['error_code'],
        ]);
    }

    /**
     * A call for a run that no longer holds its scan — usage was recorded.
     * Back in the queue (lease lost, released) → 409, abort; ended some other
     * way (cancelled, interrupted, finished) → `cancel: true`.
     */
    private function afterTheRun(AiScanStatus $status): JsonResponse
    {
        if (in_array($status, [AiScanStatus::Queued, AiScanStatus::Checking, AiScanStatus::Running], true)) {
            return self::refuse(ScannerApiCode::LeaseLost);
        }

        return new JsonResponse(['ok' => true, 'cancel' => true, 'mode' => $this->runtime->get()->mode->value, 'status' => $status->value]);
    }

    /**
     * Usage of a run that no longer holds its scan AND is no longer the
     * latest claim: the reaper put the scan back and another run claimed it,
     * the scan was released, retried or continued — or the row is gone
     * (project deleted). What the run spent was spent: the figures go to ITS
     * usage row, found by the lease token that opened it (`run` must be that
     * row's). Nothing else happens — the caller is still told `lease_lost` /
     * `stale_run` / `gone`, and no state can change from here.
     */
    private function recordReplacedRun(string $id, mixed $run, null|string $lease, mixed $usage): void
    {
        if (!Uuid::isValid($id) || $lease === null || $lease === '' || !is_array($usage)) {
            return;
        }

        if (is_string($run) && ctype_digit($run)) {
            $run = (int) $run;
        }

        $replaced = $this->usage->staleRun($id, hash('sha256', $lease));

        if ($replaced === null || $replaced['run'] !== $run) {
            return;
        }

        $this->usage->record($id, $replaced['run'], $replaced['step'], AiUsageFigures::fromPayload($usage));
        $this->logger->info('AI scan: usage of a replaced run recorded.', ['scan' => $id, 'run' => $replaced['run'], 'step' => $replaced['step']->value]);
    }

    /**
     * Loads the scan and proves the caller holds (or held) it: the lease
     * token of the latest claim and that claim's run.
     *
     * @return JsonResponse|array{row: array<string, mixed>, lane: AiScanLane, run: int, hash: string, live: bool, status: AiScanStatus, directory: string}
     */
    private function held(string $id, mixed $run, null|string $lease): JsonResponse|array
    {
        $row = $this->row($id);

        if ($row === null) {
            return self::refuse(ScannerApiCode::Gone);
        }

        if (is_string($run) && ctype_digit($run)) {
            $run = (int) $run;
        }

        $stored = self::nullableString($row['lease_token_hash']);
        $hash = hash('sha256', (string) $lease);

        if (!is_int($run) || $lease === null || $lease === '' || $stored === null || !hash_equals($stored, $hash)) {
            return self::refuse(ScannerApiCode::LeaseLost);
        }

        // The intake only ever works before the first build claim, so the
        // latest claim's lane follows from `run`.
        $lane = self::int($row['run']) > 0 ? AiScanLane::Build : AiScanLane::Intake;
        $expected = $lane === AiScanLane::Build ? self::int($row['run']) : self::int($row['intake_tries']);

        if ($run !== $expected) {
            return self::refuse(ScannerApiCode::StaleRun);
        }

        $status = AiScanStatus::from(self::string($row['status']));

        return [
            'row' => $row,
            'lane' => $lane,
            'run' => $run,
            'hash' => $hash,
            'live' => $status === $lane->liveStatus() && $row['lease_expires_at'] !== null,
            'status' => $status,
            'directory' => AiScanStorage::directory(self::string($row['project_id']), $id, self::string($row['storage_secret'])),
        ];
    }

    /**
     * @return null|array<string, mixed>
     */
    private function row(string $id): null|array
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT id, project_id, kind, status, run, intake_tries, try, approximate, lease_token_hash, lease_expires_at,
                    storage_secret, source_sha256, source_meta, options, candidates, error_code, calendar_id
               FROM ai_scan WHERE id = :id',
            ['id' => $id],
        );

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function job(array $row, AiScanLane $lane, int $run, string $leaseToken): array
    {
        $meta = self::decode($row['source_meta']);
        $leaseSeconds = $lane === AiScanLane::Intake ? AiScanLimits::INTAKE_LEASE_SECONDS : AiScanLimits::LEASE_SECONDS;

        $job = [
            'id' => $row['id'],
            'kind' => $row['kind'],
            'lane' => $lane->value,
            'run' => $run,
            'try' => self::int($row['try']),
            'approximate' => (bool) $row['approximate'],
            'leaseToken' => $leaseToken,
            'leaseSeconds' => $leaseSeconds,
            'options' => self::decode($row['options']) ?: new \stdClass(),
            'source' => [
                'sha256' => $row['source_sha256'],
                'contentType' => $lane === AiScanLane::Intake ? ($meta['contentType'] ?? null) : 'image/webp',
                'bytes' => $lane === AiScanLane::Intake ? ($meta['bytes'] ?? null) : null,
                'width' => $meta['width'] ?? null,
                'height' => $meta['height'] ?? null,
            ],
            'definition' => [
                'version' => $this->definition->version(),
                'files' => $this->definition->files(),
            ],
        ];

        if ($lane === AiScanLane::Build) {
            $job['context'] = $this->context(self::string($row['project_id']));

            if ($row['kind'] === AiScanKind::Calendar->value) {
                $job['context']['calendar'] = $this->calendarContext(self::nullableString($row['calendar_id']));
            }
        }

        return $job;
    }

    /**
     * What a build may know about the project: its font faces, its palette
     * and the stand-in asset id. No names of people or projects, no gallery.
     *
     * @return array<string, mixed>
     */
    private function context(string $projectId): array
    {
        $id = Uuid::fromString($projectId);
        $fonts = [];

        foreach ($this->getFonts->allForProject($id) as $font) {
            foreach ($font->faces as $face) {
                $fonts[] = [
                    'family' => $font->faceFamily($face),
                    'font' => $font->name,
                    'face' => $face->name,
                    'weight' => $face->weight,
                    'italic' => $face->isItalic(),
                ];
            }
        }

        $palette = [];

        foreach ($this->getProjectPalette->colorsForProject($id) as $color) {
            $palette[] = '#' . $color->color->hex;
        }

        return [
            'fonts' => $fonts,
            'palette' => array_values(array_unique($palette)),
            'standInAssetId' => AiScanStandIn::ASSET_ID,
            'templateMaxLongEdgePx' => AiScanLimits::TEMPLATE_MAX_LONG_EDGE,
        ];
    }

    /**
     * The calendar a calendar scan builds for — `{supported: false}` when it
     * is gone or cannot take a scanned layout (the build then fails).
     *
     * @return array<string, mixed>
     */
    private function calendarContext(null|string $calendarId): array
    {
        try {
            return $this->calendarCandidate->context($this->calendarRepository->get(Uuid::fromString($calendarId ?? '')));
        } catch (CalendarNotFound|CalendarModelUnavailable|\InvalidArgumentException) {
            return ['supported' => false];
        }
    }

    private function noJob(null|string $reason): JsonResponse
    {
        return new JsonResponse([
            'ok' => true,
            'mode' => $this->runtime->get()->mode->value,
            'job' => null,
            'reason' => $reason,
        ]);
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $extra what a calendar candidate answers beside the picture (view, page, layout)
     */
    private static function rendered(array $candidate, string $bytes, array $extra = []): JsonResponse
    {
        return new JsonResponse($extra + [
            'ok' => true,
            'candidate' => $candidate['n'] ?? null,
            'issues' => $candidate['issues'] ?? [],
            'width' => $candidate['width'] ?? null,
            'height' => $candidate['height'] ?? null,
            'image' => ['contentType' => 'image/webp', 'base64' => base64_encode($bytes)],
        ]);
    }

    /**
     * @param array<string, int|string> $extra
     */
    public static function refuse(ScannerApiCode $code, array $extra = []): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'code' => $code->value, ...$extra], $code->httpStatus());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function candidates(mixed $json): array
    {
        /** @var list<array<string, mixed>> $candidates */
        $candidates = array_values(array_filter(self::decode($json), is_array(...)));

        return $candidates;
    }

    /**
     * @return array<mixed>
     */
    private static function decode(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }

        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The pre-check result: codes, numbers and booleans only — one level,
     * nothing the model wrote as prose.
     *
     * @return null|array<string, mixed>
     */
    private static function flat(mixed $raw): null|array
    {
        if (!is_array($raw)) {
            return null;
        }

        $flat = [];

        foreach ($raw as $key => $value) {
            if (!is_string($key) || preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $key) !== 1) {
                continue;
            }

            if (is_bool($value) || is_int($value) || is_float($value) || (is_string($value) && preg_match('/^[a-z0-9_.\-]{1,64}$/', $value) === 1)) {
                $flat[$key] = $value;
            }
        }

        return $flat;
    }

    /**
     * Where the scanner found lines of text, for the waiting screen's
     * "Tyto texty jsme našli": the size the boxes are measured in and up to
     * 300 `[x, y, width, height]` boxes — whole numbers only, no text.
     *
     * @return null|array{width: int, height: int, boxes: list<array{int, int, int, int}>}
     */
    private static function textBoxes(mixed $raw): null|array
    {
        if (!is_array($raw) || !is_int($raw['width'] ?? null) || !is_int($raw['height'] ?? null) || !is_array($raw['boxes'] ?? null)) {
            return null;
        }

        $width = $raw['width'];
        $height = $raw['height'];

        if ($width < 1 || $height < 1 || $width > 100_000 || $height > 100_000) {
            return null;
        }

        $boxes = [];

        foreach (array_slice($raw['boxes'], 0, 300) as $box) {
            if (!is_array($box) || count($box) !== 4) {
                continue;
            }

            $numbers = [];

            foreach (array_values($box) as $value) {
                if (!is_int($value) && !is_float($value)) {
                    continue 2;
                }

                $numbers[] = (int) round($value);
            }

            $x = $numbers[0];
            $y = $numbers[1] ?? -1;
            $w = $numbers[2] ?? 0;
            $h = $numbers[3] ?? 0;

            if ($w < 1 || $h < 1 || $x < 0 || $y < 0 || $x + $w > $width || $y + $h > $height) {
                continue;
            }

            $boxes[] = [$x, $y, $w, $h];
        }

        return ['width' => $width, 'height' => $height, 'boxes' => $boxes];
    }

    /**
     * Facts the intake read while normalising — the pixel size of a HEIC
     * the web process could not read, the working copy's size, the size of
     * a PDF's first page.
     *
     * @return array<string, int|list<float|int>>
     */
    private static function sourceFacts(mixed $raw): array
    {
        $facts = [];

        foreach (['width', 'height', 'workingWidth', 'workingHeight', 'workingBytes'] as $key) {
            if (is_array($raw) && is_int($raw[$key] ?? null) && $raw[$key] > 0 && $raw[$key] < 1_000_000_000) {
                $facts[$key] = $raw[$key];
            }
        }

        // A PDF's first page: its size in points and in millimetres — what
        // the template's format is made of (ScanProposal::pdfPageFormat()).
        foreach (['pagePt', 'pageMm'] as $key) {
            $pair = is_array($raw) && is_array($raw[$key] ?? null) && array_is_list($raw[$key]) && count($raw[$key]) === 2 ? $raw[$key] : null;

            if ($pair !== null && array_filter($pair, static fn (mixed $side): bool => (is_int($side) || is_float($side)) && $side > 0 && $side < 100_000) === $pair) {
                $facts[$key] = $pair;
            }
        }

        return $facts;
    }

    /**
     * What the scanner measured on the kept candidate (boxes, colours, line
     * widths, counts): numbers, ids, codes and hex colours, to a bounded
     * depth and size. Text of the design lives in the document, not here.
     *
     * @return array<string, mixed>
     */
    private static function measured(mixed $raw): array
    {
        /** @var array<string, mixed> $clean */
        $clean = is_array($raw) ? (self::scrub($raw, 0) ?? []) : [];

        return $clean;
    }

    /**
     * @param array<mixed> $value
     * @return null|array<mixed>
     */
    private static function scrub(array $value, int $depth): null|array
    {
        if ($depth > 4 || count($value) > 2000) {
            return null;
        }

        $clean = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/^[A-Za-z0-9_.:\-]{1,64}$/', $key) !== 1) {
                continue;
            }

            if (is_array($item)) {
                $nested = self::scrub($item, $depth + 1);

                if ($nested !== null) {
                    $clean[$key] = $nested;
                }
            } elseif (is_bool($item) || is_int($item) || is_float($item) || $item === null) {
                $clean[$key] = $item;
            } elseif (is_string($item) && preg_match('/^[#A-Za-z0-9_.:\-]{1,64}$/', $item) === 1) {
                $clean[$key] = $item;
            }
        }

        return $clean;
    }

    /**
     * @return null|array<string, int|float>
     */
    private static function resources(mixed $raw): null|array
    {
        if (!is_array($raw)) {
            return null;
        }

        $resources = [];

        foreach ($raw as $key => $value) {
            if (is_string($key) && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $key) === 1 && (is_int($value) || is_float($value)) && count($resources) < 20) {
                $resources[$key] = $value;
            }
        }

        return $resources === [] ? null : $resources;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $raw, int $maxItems, int $maxLength): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $strings = [];

        foreach ($raw as $value) {
            if (is_string($value) && $value !== '' && count($strings) < $maxItems) {
                $strings[] = mb_substr($value, 0, $maxLength);
            }
        }

        return $strings;
    }

    private static function version(mixed $value): null|string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9._+\-]{1,32}$/', $value) === 1 ? $value : null;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function string(mixed $value, string $default = ''): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function nullableString(mixed $value): null|string
    {
        return is_string($value) ? $value : null;
    }
}
