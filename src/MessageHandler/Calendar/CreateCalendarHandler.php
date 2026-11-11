<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Calendar;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\Calendar\CreateCalendar;
use WBoost\Web\Repository\CalendarRepository;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\Calendar\CalendarContentSeed;
use WBoost\Web\Services\Calendar\CalendarHistoryWriter;
use WBoost\Web\Services\Calendar\DescribeCalendarChange;
use WBoost\Web\Value\Calendar\CalendarActor;
use WBoost\Web\Value\Calendar\CalendarType;

#[AsMessageHandler]
readonly final class CreateCalendarHandler
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private CalendarRepository $calendarRepository,
        private CalendarHistoryWriter $history,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ProjectNotFound
     */
    public function __invoke(CreateCalendar $message): void
    {
        $project = $this->projectRepository->get($message->projectId);
        $now = $this->clock->now();
        $year = (int) $now->format('Y') + 1;

        $calendar = new Calendar(
            $message->calendarId,
            $project,
            sprintf('Stolní kalendář %d', $year),
            $year,
            CalendarType::Week,
            CalendarContentSeed::initial(),
            Calendar::newClientToken(),
            $now,
        );

        $this->calendarRepository->add($calendar);
        $this->history->record($calendar, CalendarActor::designer($message->userId), DescribeCalendarChange::CREATED, $now);
    }
}
