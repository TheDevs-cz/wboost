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
use WBoost\Web\Services\Calendar\CalendarImportHttp;
use WBoost\Web\Services\Security\CalendarVoter;

/**
 * „Nahrát z Excelu“ in Odpady and Akce a spolky (K10b) for the designer and
 * the read-only member — {@see CalendarImportHttp}.
 */
final class CalendarImportController extends AbstractController
{
    public const string KIND_REQUIREMENT = 'waste|events';

    public function __construct(
        readonly private CalendarImportHttp $http,
    ) {
    }

    #[Route(path: '/calendar/{calendarId}/import/table', name: 'calendar_import_table', requirements: ['calendarId' => Calendar::ID_REQUIREMENT], methods: ['POST'])]
    #[IsGranted(CalendarVoter::VIEW, 'calendar')]
    public function table(
        #[MapEntity(id: 'calendarId')]
        Calendar $calendar,
        Request $request,
    ): Response {
        return $this->http->table($request);
    }

    #[Route(path: '/calendar/{calendarId}/import/template/{kind}', name: 'calendar_import_template', requirements: ['calendarId' => Calendar::ID_REQUIREMENT, 'kind' => self::KIND_REQUIREMENT], methods: ['GET'])]
    #[IsGranted(CalendarVoter::VIEW, 'calendar')]
    public function template(
        #[MapEntity(id: 'calendarId')]
        Calendar $calendar,
        string $kind,
    ): Response {
        return $this->http->template($calendar, $kind);
    }
}
