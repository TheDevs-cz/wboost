<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Calendar;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Services\Calendar\CalendarAccess;
use WBoost\Web\Services\Calendar\CalendarResponses;
use WBoost\Web\Services\Calendar\CalendarHistoryList;

/**
 * Historie for the holder of the client link — public, hence with a budget
 * per link (`calendar_client_history`).
 */
final class ClientCalendarHistoryController extends AbstractController
{
    public const string TOO_MANY_REQUESTS = 'Příliš mnoho požadavků. Zkuste to prosím za chvíli znovu.';

    public function __construct(
        readonly private CalendarAccess $access,
        readonly private CalendarHistoryList $historyList,
        #[Autowire(service: 'limiter.calendar_client_history')]
        readonly private RateLimiterFactoryInterface $historyLimiter,
    ) {
    }

    #[Route(path: '/k/{token}/history', name: 'calendar_client_history', requirements: ['token' => ClientCalendarController::TOKEN_REQUIREMENT], methods: ['GET'])]
    public function __invoke(string $token): Response
    {
        [$calendar] = $this->access->forToken($token);

        return CalendarResponses::overBudget($this->historyLimiter->create(self::limiterKey($token))->consume(), self::TOO_MANY_REQUESTS)
            ?? $this->historyList->response($calendar);
    }

    public static function limiterKey(string $token): string
    {
        return hash('xxh128', $token);
    }
}
