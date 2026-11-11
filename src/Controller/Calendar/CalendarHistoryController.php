<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Calendar;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Services\Calendar\CalendarHistoryList;
use WBoost\Web\Services\Security\CalendarVoter;

/** Historie: `{ entries: [{ who, what, at }] }`, newest first. */
final class CalendarHistoryController extends AbstractController
{
    public function __construct(
        readonly private CalendarHistoryList $historyList,
    ) {
    }

    #[Route(path: '/calendar/{calendarId}/history', name: 'calendar_history', requirements: ['calendarId' => Calendar::ID_REQUIREMENT], methods: ['GET'])]
    #[IsGranted(CalendarVoter::VIEW, 'calendar')]
    public function __invoke(
        #[MapEntity(id: 'calendarId')]
        Calendar $calendar,
    ): Response {
        return $this->historyList->response($calendar);
    }
}
