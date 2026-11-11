<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Calendar;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Services\Calendar\CalendarDocument;
use WBoost\Web\Services\Security\CalendarVoter;

/**
 * The document one card of the calendar list needs to draw its thumbnail and
 * summary line: `{ calendar, assets }`. The list page itself carries no
 * document — the `calendar-list` controller fetches this when a card scrolls
 * into view, so the page's size and query count do not grow with what the
 * calendars hold (content of up to 2 MB each).
 *
 * The same document the workspace bootstrap carries, without the session
 * part (`env`) and — like every answer outside the designer's client-link
 * dialog — without the client token.
 */
final class CalendarCardController extends AbstractController
{
    public function __construct(
        readonly private CalendarDocument $document,
    ) {
    }

    #[Route(path: '/calendar/{calendarId}/card', name: 'calendar_card', requirements: ['calendarId' => Calendar::ID_REQUIREMENT], methods: ['GET'])]
    #[IsGranted(CalendarVoter::VIEW, 'calendar')]
    public function __invoke(
        #[MapEntity(id: 'calendarId')]
        Calendar $calendar,
    ): Response {
        return new JsonResponse($this->document->card($calendar));
    }
}
