<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Calendar;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Services\Calendar\CalendarAccess;
use WBoost\Web\Services\Calendar\CalendarHistoryList;

/** Historie for the holder of the client link. */
final class ClientCalendarHistoryController extends AbstractController
{
    public function __construct(
        readonly private CalendarAccess $access,
        readonly private CalendarHistoryList $historyList,
    ) {
    }

    #[Route(path: '/k/{token}/history', name: 'calendar_client_history', requirements: ['token' => ClientCalendarController::TOKEN_REQUIREMENT], methods: ['GET'])]
    public function __invoke(string $token): Response
    {
        [$calendar] = $this->access->forToken($token);

        return $this->historyList->response($calendar);
    }
}
