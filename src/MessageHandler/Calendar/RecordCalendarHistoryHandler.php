<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Calendar;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\CalendarNotFound;
use WBoost\Web\Message\Calendar\RecordCalendarHistory;
use WBoost\Web\Repository\CalendarRepository;
use WBoost\Web\Services\Calendar\CalendarHistoryWriter;

#[AsMessageHandler]
readonly final class RecordCalendarHistoryHandler
{
    public function __construct(
        private CalendarRepository $calendarRepository,
        private CalendarHistoryWriter $history,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CalendarNotFound
     */
    public function __invoke(RecordCalendarHistory $message): void
    {
        $this->history->record(
            $this->calendarRepository->get($message->calendarId),
            $message->actor,
            $message->description,
            $this->clock->now(),
        );
    }
}
