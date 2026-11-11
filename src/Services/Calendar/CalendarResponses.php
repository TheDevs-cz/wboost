<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\RateLimiter\RateLimit;
use WBoost\Web\Exceptions\CalendarChangeForbidden;
use WBoost\Web\Exceptions\InvalidCalendarChange;

/**
 * The JSON error answers shared by the calendar endpoints
 * (docs/calendar/architecture.md §C.4, §C.5): always `{ "error": <Czech text> }`.
 */
final class CalendarResponses
{
    public const string CSRF_EXPIRED = 'Platnost formuláře vypršela. Obnovte stránku.';

    public static function csrfExpired(): JsonResponse
    {
        return self::error(self::CSRF_EXPIRED, Response::HTTP_FORBIDDEN);
    }

    public static function error(string $message, int $status = Response::HTTP_UNPROCESSABLE_ENTITY): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }

    /**
     * The 429 of a public client-link endpoint whose budget is used up, or
     * null while there is budget left.
     */
    public static function overBudget(RateLimit $limit, string $message): null|JsonResponse
    {
        if ($limit->isAccepted()) {
            return null;
        }

        $response = self::error($message, Response::HTTP_TOO_MANY_REQUESTS);
        $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));

        return $response;
    }

    /**
     * Runs `$action` and turns the two calendar refusals into their JSON
     * answer — also when Messenger wrapped them in a HandlerFailedException.
     *
     * @param callable(): JsonResponse $action
     */
    public static function guard(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (InvalidCalendarChange $invalid) {
            return self::error($invalid->getMessage());
        } catch (CalendarChangeForbidden $forbidden) {
            return self::error($forbidden->getMessage(), Response::HTTP_FORBIDDEN);
        } catch (HandlerFailedException $failed) {
            $real = $failed->getPrevious();

            if ($real instanceof InvalidCalendarChange) {
                return self::error($real->getMessage());
            }

            if ($real instanceof CalendarChangeForbidden) {
                return self::error($real->getMessage(), Response::HTTP_FORBIDDEN);
            }

            throw $failed;
        }
    }
}
