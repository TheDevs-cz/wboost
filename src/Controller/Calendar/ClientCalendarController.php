<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Calendar;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Services\Calendar\CalendarAccess;
use WBoost\Web\Services\Calendar\CalendarDocument;

/**
 * The client view behind the unguessable link — public, no login. The token
 * addresses the calendar by itself (never combined with the calendar id) and
 * grants the client role for that one calendar: step 5 with the client
 * tools. An unknown or regenerated token is a 404.
 */
final class ClientCalendarController extends AbstractController
{
    public const string TOKEN_REQUIREMENT = '[a-f0-9]{64}';

    public function __construct(
        readonly private CalendarAccess $access,
        readonly private CalendarDocument $document,
        readonly private GetFonts $getFonts,
    ) {
    }

    #[Route(path: '/k/{token}', name: 'calendar_client', requirements: ['token' => self::TOKEN_REQUIREMENT], methods: ['GET'])]
    public function __invoke(string $token): Response
    {
        [$calendar, $actor] = $this->access->forToken($token);

        $response = $this->render('calendar/client.html.twig', [
            'calendar' => $calendar,
            'bootstrap_json' => CalendarDocument::encode($this->document->forCalendar($calendar, $actor, true)),
            'fonts' => $this->getFonts->allForProject($calendar->project->id),
        ]);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
