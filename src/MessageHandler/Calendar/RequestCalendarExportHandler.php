<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Calendar;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\CalendarExport;
use WBoost\Web\Exceptions\CalendarNotFound;
use WBoost\Web\Exceptions\InvalidCalendarChange;
use WBoost\Web\Message\Calendar\RequestCalendarExport;
use WBoost\Web\Repository\CalendarExportRepository;
use WBoost\Web\Repository\CalendarRepository;
use WBoost\Web\Services\Calendar\CalendarExports;
use WBoost\Web\Services\Calendar\CalendarHistoryWriter;
use WBoost\Web\Services\Calendar\CalendarModuleInliner;
use WBoost\Web\Services\Calendar\DescribeCalendarChange;

#[AsMessageHandler(bus: 'command_bus')]
readonly final class RequestCalendarExportHandler
{
    public function __construct(
        private CalendarRepository $calendarRepository,
        private CalendarExportRepository $exportRepository,
        private CalendarExports $exports,
        private CalendarModuleInliner $modules,
        private CalendarHistoryWriter $history,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CalendarNotFound
     * @throws InvalidCalendarChange the calendar has no layout
     */
    public function __invoke(RequestCalendarExport $message): void
    {
        // The row lock serialises concurrent requests: the second one finds the first one's job.
        $calendar = $this->calendarRepository->getForUpdate($message->calendarId);

        if ($calendar->layout === null) {
            throw new InvalidCalendarChange(CalendarExports::NO_LAYOUT);
        }

        $now = $this->clock->now();

        if (!$message->reuse || $this->exports->reusable($calendar, $message->variant) === null) {
            $this->exportRepository->add(new CalendarExport(
                $message->exportId,
                $calendar,
                $message->variant,
                $calendar->revision,
                $this->modules->fingerprint(),
                $message->actor->kind,
                $this->history->user($message->actor),
                $now,
            ));
        }

        $this->history->record($calendar, $message->actor, DescribeCalendarChange::exported($message->variant), $now);
        $this->history->prune($calendar);
    }
}
