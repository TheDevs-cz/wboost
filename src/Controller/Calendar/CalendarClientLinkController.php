<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Calendar;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Message\Calendar\RecordCalendarHistory;
use WBoost\Web\Message\Calendar\RegenerateCalendarClientLink;
use WBoost\Web\Services\Calendar\CalendarAccess;
use WBoost\Web\Services\Calendar\CalendarDocument;
use WBoost\Web\Services\Calendar\CalendarResponses;
use WBoost\Web\Services\Calendar\DescribeCalendarChange;
use WBoost\Web\Services\Security\CalendarVoter;

/**
 * Odkaz pro klienta: `action = copied` only records that the designer copied
 * the link; `action = regenerate` replaces the token, so the old link stops
 * working. Answers `{ url }` — the current absolute client link.
 */
final class CalendarClientLinkController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private CalendarAccess $access,
        readonly private CalendarDocument $document,
    ) {
    }

    #[Route(path: '/calendar/{calendarId}/client-link', name: 'calendar_client_link', requirements: ['calendarId' => Calendar::ID_REQUIREMENT], methods: ['POST'])]
    #[IsGranted(CalendarVoter::EDIT, 'calendar')]
    public function __invoke(
        #[MapEntity(id: 'calendarId')]
        Calendar $calendar,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid(CalendarDocument::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            return CalendarResponses::csrfExpired();
        }

        $actor = $this->access->forUser($calendar);

        switch ($request->request->getString('action')) {
            case 'copied':
                $this->bus->dispatch(new RecordCalendarHistory($calendar->id, $actor, DescribeCalendarChange::LINK_COPIED));
                break;
            case 'regenerate':
                $this->bus->dispatch(new RegenerateCalendarClientLink($calendar->id, $actor));
                break;
            default:
                return CalendarResponses::error('Neznámá akce.', Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['url' => $this->document->clientUrl($calendar)]);
    }
}
