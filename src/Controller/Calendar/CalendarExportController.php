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
use WBoost\Web\Services\Calendar\CalendarExportHttp;
use WBoost\Web\Services\Calendar\CalendarResponses;
use WBoost\Web\Services\Security\CalendarVoter;

/**
 * „Stáhnout PDF“ for a logged-in user — the designer and the read-only
 * member alike: whoever may open the calendar may export it
 * (docs/architecture/calendars.md § "PDF export").
 *
 * - POST `…/export` (`_token`, `variant` = print | approval) → `{ export }`
 * - GET `…/export/{exportId}` → `{ export }` (polled while the job runs)
 * - GET `…/export/{exportId}/download` → the PDF
 */
final class CalendarExportController extends AbstractController
{
    public function __construct(
        readonly private CalendarAccess $access,
        readonly private CalendarExportHttp $http,
    ) {
    }

    #[Route(path: '/calendar/{calendarId}/export', name: 'calendar_export_request', requirements: ['calendarId' => Calendar::ID_REQUIREMENT], methods: ['POST'])]
    #[IsGranted(CalendarVoter::VIEW, 'calendar')]
    public function request(
        #[MapEntity(id: 'calendarId')]
        Calendar $calendar,
        Request $request,
    ): Response {
        if (!$this->http->csrfValid($request)) {
            return CalendarResponses::csrfExpired();
        }

        $variant = $this->http->variant($request);

        if ($variant === null) {
            return CalendarResponses::error(CalendarExportHttp::UNKNOWN_VARIANT, Response::HTTP_BAD_REQUEST);
        }

        return $this->http->request($calendar, $variant, $this->access->forUser($calendar));
    }

    #[Route(path: '/calendar/{calendarId}/export/{exportId}', name: 'calendar_export_status', requirements: ['calendarId' => Calendar::ID_REQUIREMENT, 'exportId' => Calendar::ID_REQUIREMENT], methods: ['GET'])]
    #[IsGranted(CalendarVoter::VIEW, 'calendar')]
    public function status(
        #[MapEntity(id: 'calendarId')]
        Calendar $calendar,
        string $exportId,
    ): Response {
        return $this->http->status($calendar, $exportId);
    }

    #[Route(path: '/calendar/{calendarId}/export/{exportId}/download', name: 'calendar_export_download', requirements: ['calendarId' => Calendar::ID_REQUIREMENT, 'exportId' => Calendar::ID_REQUIREMENT], methods: ['GET'])]
    #[IsGranted(CalendarVoter::VIEW, 'calendar')]
    public function download(
        #[MapEntity(id: 'calendarId')]
        Calendar $calendar,
        string $exportId,
    ): Response {
        return $this->http->download($calendar, $exportId);
    }
}
