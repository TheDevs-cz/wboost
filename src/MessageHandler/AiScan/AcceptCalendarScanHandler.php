<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\AiScan;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\AiScan;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Exceptions\AiScanNotFound;
use WBoost\Web\Exceptions\CalendarModelRefused;
use WBoost\Web\Exceptions\CalendarModelUnavailable;
use WBoost\Web\Exceptions\CalendarNotFound;
use WBoost\Web\Exceptions\InvalidCalendarChange;
use WBoost\Web\Message\AiScan\AcceptCalendarScan;
use WBoost\Web\Message\Calendar\SaveCalendar;
use WBoost\Web\Repository\AiScanRepository;
use WBoost\Web\Repository\CalendarRepository;
use WBoost\Web\Services\AiScan\AiScanTransitions;
use WBoost\Web\Services\AiScan\Calendar\CalendarScanPictures;
use WBoost\Web\Services\AiScan\Calendar\CalendarScanProposal;
use WBoost\Web\Services\Calendar\CalendarAssetUploader;
use WBoost\Web\Services\Calendar\CalendarModelBridge;
use WBoost\Web\Services\Calendar\DescribeCalendarChange;
use WBoost\Web\Value\AiScan\AiScanKind;
use WBoost\Web\Value\AiScan\AiScanRefusal;
use WBoost\Web\Value\AiScan\AiScanRefused;
use WBoost\Web\Value\AiScan\AiScanStatus;
use WBoost\Web\Value\Calendar\CalendarActor;
use WBoost\Web\Value\Calendar\CalendarSavePayload;
use WBoost\Web\Value\Calendar\CalendarType;

/**
 * Rule 14 for calendars. Under the scan's row lock and the calendar's row
 * lock, in the bus's transaction — so either all of it happened or none:
 *
 * 1. a repeat on an `accepted` scan answers with the first result;
 * 2. what the calendar holds NOW is remembered (layout, layout choice,
 *    switches, marker) — a save through the client link in between changed
 *    content only, and content is not touched here;
 * 3. the pictures held for the proposal's places become calendar assets;
 * 4. the layout to apply is computed against the calendar as it is
 *    ({@see CalendarScanProposal::plan()}), handed to the calendar's own
 *    model (`replace_layout`, the ticked switches) and saved through
 *    {@see SaveCalendar} — the one write path — with the marker
 *    `layout_origin = scan` and the history line „Převzal rozvržení ze skenu“;
 * 5. the scan becomes `accepted` and keeps the snapshot with a hash of what
 *    was written, until its `expires_at`.
 */
#[AsMessageHandler]
readonly final class AcceptCalendarScanHandler
{
    public const string ORIGIN = 'scan';

    public function __construct(
        private Connection $connection,
        private MessageBusInterface $bus,
        private AiScanRepository $repository,
        private CalendarRepository $calendars,
        private AiScanTransitions $transitions,
        private CalendarScanPictures $pictures,
        private CalendarAssetUploader $uploader,
        private CalendarModelBridge $model,
    ) {
    }

    /**
     * @return array{calendarId: string, revision: int, features: list<string>, pictures: int, unfilledPictures: int, restoreUntil: null|string, created: bool}
     *
     * @throws AiScanNotFound
     * @throws AiScanRefused `wrong_state`, `invalid_fix` (a held picture the calendar does not take, a layout the calendar refuses), `temporarily_unavailable`
     */
    public function __invoke(AcceptCalendarScan $message): array
    {
        $status = $this->connection->fetchOne('SELECT status FROM ai_scan WHERE id = :id FOR UPDATE', ['id' => $message->scanId->toString()]);

        if ($status === false) {
            throw new AiScanNotFound();
        }

        $scan = $this->repository->get($message->scanId);

        if ($scan->kind !== AiScanKind::Calendar || $scan->calendar === null) {
            throw new AiScanRefused(AiScanRefusal::WrongState);
        }

        if ($scan->status === AiScanStatus::Accepted) {
            return $this->result($scan, $scan->calendar, false);
        }

        if ($scan->status !== AiScanStatus::Ready || CalendarScanProposal::layout($scan) === null) {
            throw new AiScanRefused(AiScanRefusal::WrongState);
        }

        try {
            $calendar = $this->calendars->getForUpdate($scan->calendar->id);
        } catch (CalendarNotFound) {
            throw new AiScanRefused(AiScanRefusal::WrongState);
        }

        if ($calendar->type !== CalendarType::Week) {
            throw new AiScanRefused(AiScanRefusal::WrongState);
        }

        $actor = CalendarActor::designer($message->userId);
        $snapshot = CalendarScanProposal::snapshot($calendar);
        $assets = $this->assets($scan, $calendar, $actor);
        $plan = CalendarScanProposal::plan($scan, $calendar, $assets);
        assert($plan !== null);

        $features = array_values(array_intersect(CalendarScanProposal::featuresOffered($scan, $calendar), $message->features));
        $commands = [];

        if ($features !== []) {
            $commands[] = ['op' => 'configure', 'features' => array_fill_keys($features, true)];
        }

        $commands[] = ['op' => 'replace_layout', 'layout' => $plan['layout']];

        try {
            $model = $this->model->run($calendar, $commands);
            $design = CalendarSavePayload::fromJson($model->payload ?? '{}')->design ?? new \stdClass();
            // The layout choice the proposal was built from; the model keeps the calendar's own.
            $design->preset = $plan['preset'];

            $this->bus->dispatch(new SaveCalendar(
                $calendar->id,
                $actor,
                $design,
                setLayoutOrigin: true,
                layoutOrigin: self::ORIGIN,
                designSentence: DescribeCalendarChange::SCAN_APPLIED,
            ));
        } catch (CalendarModelUnavailable) {
            throw new AiScanRefused(AiScanRefusal::TemporarilyUnavailable);
        } catch (CalendarModelRefused|InvalidCalendarChange) {
            throw new AiScanRefused(AiScanRefusal::InvalidFix, ['field' => 'layout']);
        } catch (HandlerFailedException $failed) {
            if ($failed->getPrevious() instanceof InvalidCalendarChange) {
                throw new AiScanRefused(AiScanRefusal::InvalidFix, ['field' => 'layout']);
            }

            throw $failed;
        }

        // The save handler locked and refreshed this very instance.
        $restore = $snapshot + [
            'appliedHash' => CalendarScanProposal::hash($calendar->layout, $calendar->features),
            'appliedRevision' => $calendar->revision,
            'features' => $features,
            'assets' => $assets === [] ? new \stdClass() : $assets,
        ];

        if (!$this->transitions->acceptCalendar($scan->id->toString(), $restore)) {
            throw new \LogicException('A locked ready calendar scan could not be accepted.');
        }

        return $this->result($this->repository->get($scan->id), $calendar, true);
    }

    /**
     * The held pictures as calendar assets: slot → asset id.
     *
     * @return array<string, string>
     *
     * @throws AiScanRefused
     */
    private function assets(AiScan $scan, Calendar $calendar, CalendarActor $actor): array
    {
        $assets = [];
        $slots = CalendarScanProposal::slots($scan);

        foreach (CalendarScanPictures::held($scan) as $slot => $held) {
            if (!in_array($slot, $slots, true)) {
                continue;
            }

            $picture = $this->pictures->read($scan, $slot);

            if ($picture === null) {
                throw new AiScanRefused(AiScanRefusal::InvalidFix, ['field' => 'pictures']);
            }

            $path = tempnam(sys_get_temp_dir(), 'wb-scan-picture-');

            if ($path === false) {
                throw new \RuntimeException('No temporary file for a held picture.');
            }

            try {
                file_put_contents($path, $picture['bytes']);
                $assets[$slot] = $this->uploader
                    ->storeFile($calendar, $actor, CalendarScanProposal::SLOTS[$slot]['kind'], new UploadedFile($path, $held['name'], null, null, true))
                    ->toString();
            } catch (InvalidCalendarChange) {
                throw new AiScanRefused(AiScanRefusal::InvalidFix, ['field' => 'pictures']);
            } finally {
                @unlink($path);
            }
        }

        return $assets;
    }

    /**
     * @return array{calendarId: string, revision: int, features: list<string>, pictures: int, unfilledPictures: int, restoreUntil: null|string, created: bool}
     */
    private function result(AiScan $scan, Calendar $calendar, bool $created): array
    {
        $restore = $scan->restore ?? [];
        /** @var list<string> $features */
        $features = is_array($restore['features'] ?? null) ? array_values(array_filter($restore['features'], is_string(...))) : [];
        $assets = is_array($restore['assets'] ?? null) ? $restore['assets'] : [];
        // The scan's places for a picture that are empty in the calendar AS IT IS
        // NOW — the same question for the apply and for a repeated call (which
        // used to answer 0): after the apply the calendar's layout carries the
        // pictures that were held with the scan, and whatever was added or taken
        // away since counts as well.
        $plan = CalendarScanProposal::plan($scan, $calendar);
        $slots = CalendarScanProposal::slots($scan);
        $filled = count(array_intersect($slots, $plan['currentPictures'] ?? []));

        return [
            'calendarId' => $calendar->id->toString(),
            'revision' => $calendar->revision,
            'features' => $features,
            'pictures' => count($assets),
            'unfilledPictures' => count($slots) - $filled,
            'restoreUntil' => $scan->expiresAt?->format(DATE_ATOM),
            'created' => $created,
        ];
    }
}
