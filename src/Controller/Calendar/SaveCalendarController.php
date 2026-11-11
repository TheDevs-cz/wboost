<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Calendar;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Services\Calendar\CalendarAccess;
use WBoost\Web\Services\Calendar\CalendarSaver;
use WBoost\Web\Services\Security\CalendarVoter;

/**
 * Saves the workspace: `_token` + `payload` (`{ design?, content?, finish? }`),
 * answering the stored document (docs/calendar/architecture.md §C.5). Open
 * to everyone who may VIEW the calendar — what a read-only member (the
 * client role) may change is decided by the handler, not by the route.
 */
final class SaveCalendarController extends AbstractController
{
    public function __construct(
        readonly private CalendarAccess $access,
        readonly private CalendarSaver $saver,
    ) {
    }

    #[Route(path: '/calendar/{calendarId}/save', name: 'calendar_save', requirements: ['calendarId' => Calendar::ID_REQUIREMENT], methods: ['POST'])]
    #[IsGranted(CalendarVoter::VIEW, 'calendar')]
    public function __invoke(
        #[MapEntity(id: 'calendarId')]
        Calendar $calendar,
        Request $request,
    ): Response {
        return $this->saver->save($request, $calendar, $this->access->forUser($calendar));
    }
}
