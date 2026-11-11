<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Calendar;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Services\Calendar\CalendarAccess;
use WBoost\Web\Services\Calendar\CalendarResponses;
use WBoost\Web\Services\Calendar\CalendarSaver;

/**
 * The save endpoint of the client link. The actor is always a client: any
 * design key, `finish` or a content collection outside the client whitelist
 * refuses the whole request (403), whatever the page's JavaScript sends.
 *
 * Public, so saves have a budget per link (`calendar_client_save` in
 * config/packages/rate_limiter.php) — every one takes a row lock and writes
 * history. Over it: 429 in the calendar endpoints' JSON error shape.
 */
final class SaveClientCalendarController extends AbstractController
{
    public const string TOO_MANY_SAVES = 'Ukládáte příliš často. Zkuste to prosím za chvíli znovu.';

    public function __construct(
        readonly private CalendarAccess $access,
        readonly private CalendarSaver $saver,
        #[Autowire(service: 'limiter.calendar_client_save')]
        readonly private RateLimiterFactoryInterface $saveLimiter,
    ) {
    }

    #[Route(path: '/k/{token}/save', name: 'calendar_client_save', requirements: ['token' => ClientCalendarController::TOKEN_REQUIREMENT], methods: ['POST'])]
    public function __invoke(string $token, Request $request): Response
    {
        // An unknown token is a 404 before anything is counted.
        [$calendar, $actor] = $this->access->forToken($token);

        return CalendarResponses::overBudget($this->saveLimiter->create(self::limiterKey($token))->consume(), self::TOO_MANY_SAVES)
            ?? $this->saver->save($request, $calendar, $actor);
    }

    public static function limiterKey(string $token): string
    {
        return hash('xxh128', $token);
    }
}
