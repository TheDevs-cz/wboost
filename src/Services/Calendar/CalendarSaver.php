<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Message\Calendar\SaveCalendar;
use WBoost\Web\Value\Calendar\CalendarActor;
use WBoost\Web\Value\Calendar\CalendarSavePayload;

/**
 * The save endpoint behind both routes — the designer's
 * `/calendar/{id}/save` and the client link's `/k/{token}/save`
 * (docs/calendar/architecture.md §C.5): multipart `_token` + `payload`,
 * answering the stored document or `{ error }`.
 */
readonly final class CalendarSaver
{
    public function __construct(
        private MessageBusInterface $bus,
        private CalendarDocument $document,
        private CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    public function save(Request $request, Calendar $calendar, CalendarActor $actor): JsonResponse
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(CalendarDocument::CSRF_TOKEN_ID, $request->request->getString('_token')))) {
            return CalendarResponses::csrfExpired();
        }

        return CalendarResponses::guard(function () use ($request, $calendar, $actor): JsonResponse {
            $payload = CalendarSavePayload::fromJson($request->request->getString('payload'));

            $this->bus->dispatch(new SaveCalendar($calendar->id, $actor, $payload->design, $payload->ops, $payload->finish, setLayoutOrigin: $payload->clearLayoutOrigin));

            // The handler locked and refreshed this very instance.
            return new JsonResponse($this->document->saved($calendar, $actor->isDesigner()));
        });
    }
}
