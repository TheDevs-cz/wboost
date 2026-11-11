<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Calendar;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Services\Calendar\CalendarAccess;
use WBoost\Web\Services\Calendar\CalendarSaver;

/**
 * The save endpoint of the client link. The actor is always a client: any
 * design key, `finish` or a content collection outside the client whitelist
 * refuses the whole request (403), whatever the page's JavaScript sends.
 */
final class SaveClientCalendarController extends AbstractController
{
    public function __construct(
        readonly private CalendarAccess $access,
        readonly private CalendarSaver $saver,
    ) {
    }

    #[Route(path: '/k/{token}/save', name: 'calendar_client_save', requirements: ['token' => ClientCalendarController::TOKEN_REQUIREMENT], methods: ['POST'])]
    public function __invoke(string $token, Request $request): Response
    {
        [$calendar, $actor] = $this->access->forToken($token);

        return $this->saver->save($request, $calendar, $actor);
    }
}
