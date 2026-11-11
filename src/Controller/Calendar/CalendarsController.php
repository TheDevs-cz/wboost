<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Calendar;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Attribute\RequiresProjectModule;
use WBoost\Web\Entity\Project;
use WBoost\Web\Query\GetCalendars;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Services\Calendar\CalendarDocument;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Value\ProjectModule;

/**
 * Projekt › Kalendáře — the list of a project's calendars (the module's
 * landing page). Cards are completed by the `calendar-list` Stimulus
 * controller from the JSON emitted next to each one (thumbnail, summary);
 * without it the page still lists, opens, creates and deletes calendars.
 */
final class CalendarsController extends AbstractController
{
    public function __construct(
        readonly private GetCalendars $getCalendars,
        readonly private GetFonts $getFonts,
        readonly private CalendarDocument $document,
    ) {
    }

    #[Route(path: '/project/{projectId}/calendars', name: 'calendars', methods: ['GET'])]
    #[IsGranted(ProjectVoter::VIEW, 'project')]
    #[RequiresProjectModule(ProjectModule::Calendars)]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
    ): Response {
        $cards = [];

        foreach ($this->getCalendars->forProject($project->id) as $row) {
            $calendar = $row->calendar;
            $id = ['calendarId' => $calendar->id->toString()];

            $cards[] = [
                'calendar' => $calendar,
                'last_change_by' => $row->lastChangeBy?->label(),
                'last_change_at' => $row->lastChangeAt,
                'doc_json' => CalendarDocument::encode([
                    'calendar' => $this->document->calendar($calendar),
                    'assets' => $this->document->assets($calendar),
                    'urls' => [
                        'workspace' => $this->generateUrl('calendar_workspace', $id),
                        'history' => $this->generateUrl('calendar_history', $id),
                    ],
                ]),
            ];
        }

        return $this->render('calendar/list.html.twig', [
            'project' => $project,
            'cards' => $cards,
            'fonts' => $this->getFonts->allForProject($project->id),
            'env_json' => CalendarDocument::encode($this->document->projectEnv($project)),
        ]);
    }
}
