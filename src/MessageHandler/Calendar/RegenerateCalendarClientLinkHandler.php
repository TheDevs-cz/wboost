<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Calendar;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\CalendarNotFound;
use WBoost\Web\Message\Calendar\RegenerateCalendarClientLink;
use WBoost\Web\Repository\CalendarRepository;
use WBoost\Web\Services\Calendar\CalendarHistoryWriter;
use WBoost\Web\Services\Calendar\DescribeCalendarChange;

#[AsMessageHandler]
readonly final class RegenerateCalendarClientLinkHandler
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
    public function __invoke(RegenerateCalendarClientLink $message): void
    {
        $calendar = $this->calendarRepository->get($message->calendarId);
        $calendar->regenerateClientToken();

        $this->history->record($calendar, $message->actor, DescribeCalendarChange::LINK_REGENERATED, $this->clock->now());
    }
}
