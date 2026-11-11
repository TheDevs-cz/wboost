<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Calendar;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Message\Calendar\DeleteCalendar;
use WBoost\Web\Services\Calendar\CalendarDocument;
use WBoost\Web\Services\Calendar\CalendarResponses;
use WBoost\Web\Services\Security\CalendarVoter;

/** Deletes a calendar with its photos and content (the list asks first). */
final class DeleteCalendarController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/calendar/{calendarId}/delete', name: 'calendar_delete', requirements: ['calendarId' => Calendar::ID_REQUIREMENT], methods: ['POST'])]
    #[IsGranted(CalendarVoter::EDIT, 'calendar')]
    public function __invoke(
        #[MapEntity(id: 'calendarId')]
        Calendar $calendar,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid(CalendarDocument::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            return CalendarResponses::csrfExpired();
        }

        $projectId = $calendar->project->id->toString();
        $name = $calendar->name;

        $this->bus->dispatch(new DeleteCalendar($calendar->id));

        $this->addFlash('success', sprintf('Kalendář „%s“ smazán', $name));

        return $this->redirectToRoute('calendars', ['projectId' => $projectId]);
    }
}
