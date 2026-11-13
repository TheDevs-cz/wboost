<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\AiScan;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Exceptions\AiScanNotFound;
use WBoost\Web\Exceptions\CalendarNotFound;
use WBoost\Web\Exceptions\InvalidCalendarChange;
use WBoost\Web\Message\AiScan\RestoreCalendarScan;
use WBoost\Web\Message\Calendar\SaveCalendar;
use WBoost\Web\Repository\AiScanRepository;
use WBoost\Web\Repository\CalendarRepository;
use WBoost\Web\Services\AiScan\AiScanTransitions;
use WBoost\Web\Services\AiScan\Calendar\CalendarScanProposal;
use WBoost\Web\Services\Calendar\DescribeCalendarChange;
use WBoost\Web\Value\AiScan\AiScanKind;
use WBoost\Web\Value\AiScan\AiScanRefusal;
use WBoost\Web\Value\AiScan\AiScanRefused;
use WBoost\Web\Value\AiScan\AiScanStatus;
use WBoost\Web\Value\Calendar\CalendarActor;

/**
 * „Vrátit původní rozvržení“ (architecture.md §4.6). Under both row locks, in
 * the bus's transaction: the snapshot's layout, layout choice, switches and
 * marker go back through {@see SaveCalendar} with the history line „Vrátil
 * původní rozvržení“. Content — texts, photos, page content — and the
 * pictures that became calendar assets are not touched.
 *
 * The snapshot lives until the scan's `expires_at` (the date the review
 * showed); after that there is nothing to restore (`wrong_state`).
 */
#[AsMessageHandler]
readonly final class RestoreCalendarScanHandler
{
    public function __construct(
        private Connection $connection,
        private MessageBusInterface $bus,
        private AiScanRepository $repository,
        private CalendarRepository $calendars,
        private AiScanTransitions $transitions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{calendarId: string, revision: int, restored: bool}
     *
     * @throws AiScanNotFound
     * @throws AiScanRefused `wrong_state`, `layout_changed` (edits since the accept — repeat with `confirm`)
     */
    public function __invoke(RestoreCalendarScan $message): array
    {
        $status = $this->connection->fetchOne('SELECT status FROM ai_scan WHERE id = :id FOR UPDATE', ['id' => $message->scanId->toString()]);

        if ($status === false) {
            throw new AiScanNotFound();
        }

        $scan = $this->repository->get($message->scanId);
        $restore = $scan->restore;

        if ($scan->kind !== AiScanKind::Calendar || $scan->status !== AiScanStatus::Accepted || $scan->calendar === null
            || !is_array($restore) || $scan->expiresAt === null || $scan->expiresAt <= $this->clock->now()) {
            throw new AiScanRefused(AiScanRefusal::WrongState);
        }

        try {
            $calendar = $this->calendars->getForUpdate($scan->calendar->id);
        } catch (CalendarNotFound) {
            throw new AiScanRefused(AiScanRefusal::WrongState);
        }

        if (isset($restore['restoredAt'])) {
            // A repeat: the first call did it.
            return ['calendarId' => $calendar->id->toString(), 'revision' => $calendar->revision, 'restored' => false];
        }

        $changed = CalendarScanProposal::hash($calendar->layout, $calendar->features) !== ($restore['appliedHash'] ?? null);

        if ($changed && !$message->confirm) {
            throw new AiScanRefused(AiScanRefusal::LayoutChanged);
        }

        $layout = CalendarScanProposal::decodeLayout($restore['layoutJson'] ?? null);
        $features = json_decode(is_string($restore['featuresJson'] ?? null) ? $restore['featuresJson'] : '{}', false);
        $preset = is_string($restore['preset'] ?? null) ? $restore['preset'] : null;

        $design = new \stdClass();
        $design->layout = $layout;
        $design->preset = $preset;

        if ($features instanceof \stdClass && get_object_vars($features) !== []) {
            $design->features = $features;
        } elseif ($calendar->features !== []) {
            // The calendar had never stored its switches (all at their
            // defaults): what the accept turned on goes off again.
            $switches = $calendar->features;

            foreach (is_array($restore['features'] ?? null) ? $restore['features'] : [] as $key) {
                if (is_string($key) && array_key_exists($key, $switches)) {
                    $switches[$key] = false;
                }
            }

            $design->features = (object) $switches;
        }

        if ($layout === null && $calendar->step > 2) {
            // Without a layout there is nothing behind step 2.
            $design->step = 2;
        }

        try {
            $this->bus->dispatch(new SaveCalendar(
                $calendar->id,
                CalendarActor::designer($message->userId),
                $design,
                setLayoutOrigin: true,
                layoutOrigin: is_string($restore['layoutOrigin'] ?? null) ? $restore['layoutOrigin'] : null,
                designSentence: DescribeCalendarChange::SCAN_RESTORED,
            ));
        } catch (HandlerFailedException $failed) {
            if ($failed->getPrevious() instanceof InvalidCalendarChange) {
                throw new AiScanRefused(AiScanRefusal::WrongState);
            }

            throw $failed;
        }

        $this->transitions->markRestored($scan->id->toString());

        return ['calendarId' => $calendar->id->toString(), 'revision' => $calendar->revision, 'restored' => true];
    }
}
