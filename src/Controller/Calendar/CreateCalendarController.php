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
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\Calendar\CreateCalendar;
use WBoost\Web\Services\Calendar\CalendarDocument;
use WBoost\Web\Services\Calendar\CalendarResponses;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\ProjectVoter;

/**
 * Vytvořit kalendář: creates the calendar at once (no dialog) and opens its
 * workspace in step 1.
 */
final class CreateCalendarController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
    ) {
    }

    #[Route(path: '/project/{projectId}/calendars/create', name: 'calendar_create', methods: ['POST'])]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid(CalendarDocument::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            return CalendarResponses::csrfExpired();
        }

        $calendarId = $this->provideIdentity->next();
        $user = $this->getUser();

        $this->bus->dispatch(new CreateCalendar($calendarId, $project->id, $user instanceof User ? $user->id : null));

        return $this->redirectToRoute('calendar_workspace', ['calendarId' => $calendarId->toString()]);
    }
}
