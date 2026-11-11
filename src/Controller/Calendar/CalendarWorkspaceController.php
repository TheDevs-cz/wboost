<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Calendar;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Services\Calendar\CalendarAccess;
use WBoost\Web\Services\Calendar\CalendarDocument;
use WBoost\Web\Services\Security\CalendarVoter;

/**
 * The calendar workspace. A project editor gets the five-step designer; a
 * member the project is only shared with for reading gets the client mode
 * (step 5 with the client tools) inside the app shell. All inner DOM is
 * built by the `calendar-workspace` Stimulus controller from the bootstrap
 * JSON.
 */
final class CalendarWorkspaceController extends AbstractController
{
    public function __construct(
        readonly private CalendarAccess $access,
        readonly private CalendarDocument $document,
        readonly private GetFonts $getFonts,
    ) {
    }

    #[Route(path: '/calendar/{calendarId}', name: 'calendar_workspace', requirements: ['calendarId' => Calendar::ID_REQUIREMENT], methods: ['GET'])]
    #[IsGranted(CalendarVoter::VIEW, 'calendar')]
    public function __invoke(
        #[MapEntity(id: 'calendarId')]
        Calendar $calendar,
    ): Response {
        $bootstrap = $this->document->forCalendar($calendar, $this->access->forUser($calendar));

        return $this->render('calendar/workspace.html.twig', [
            'project' => $calendar->project,
            'calendar' => $calendar,
            'mode' => $bootstrap['mode'],
            'bootstrap_json' => CalendarDocument::encode($bootstrap),
            'fonts' => $this->getFonts->allForProject($calendar->project->id),
        ]);
    }
}
