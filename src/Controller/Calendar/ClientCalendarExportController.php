<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Calendar;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Services\Calendar\CalendarAccess;
use WBoost\Web\Services\Calendar\CalendarExportHttp;
use WBoost\Web\Services\Calendar\CalendarExports;
use WBoost\Web\Services\Calendar\CalendarResponses;

/**
 * „Stáhnout PDF“ for the holder of the client link — the same three
 * endpoints as {@see CalendarExportController}, addressed by the token.
 *
 * Public, and an export is the most expensive thing the link can ask for
 * (minutes of Chromium), so:
 * - a NEW render costs one of `calendar_client_export` (10 per hour per
 *   link). A request answered by an existing export — the calendar has not
 *   changed since — costs nothing, so downloading the same file again is
 *   never refused;
 * - polling and downloading share `calendar_client_export_read`.
 * An unknown token is a 404 before anything is counted.
 */
final class ClientCalendarExportController extends AbstractController
{
    public const string TOO_MANY_EXPORTS = 'Příliš mnoho exportů za poslední hodinu. Zkuste to prosím později.';

    public function __construct(
        readonly private CalendarAccess $access,
        readonly private CalendarExportHttp $http,
        readonly private CalendarExports $exports,
        #[Autowire(service: 'limiter.calendar_client_export')]
        readonly private RateLimiterFactoryInterface $exportLimiter,
        #[Autowire(service: 'limiter.calendar_client_export_read')]
        readonly private RateLimiterFactoryInterface $readLimiter,
    ) {
    }

    #[Route(path: '/k/{token}/export', name: 'calendar_client_export_request', requirements: ['token' => ClientCalendarController::TOKEN_REQUIREMENT], methods: ['POST'])]
    public function request(string $token, Request $request): Response
    {
        [$calendar, $actor] = $this->access->forToken($token);

        if (!$this->http->csrfValid($request)) {
            return CalendarResponses::csrfExpired();
        }

        $variant = $this->http->variant($request);

        if ($variant === null) {
            return CalendarResponses::error(CalendarExportHttp::UNKNOWN_VARIANT, Response::HTTP_BAD_REQUEST);
        }

        if ($this->exports->reusable($calendar, $variant) === null) {
            $refusal = CalendarResponses::overBudget($this->exportLimiter->create(self::limiterKey($token))->consume(), self::TOO_MANY_EXPORTS);

            if ($refusal !== null) {
                return $refusal;
            }
        }

        return $this->http->request($calendar, $variant, $actor, $token);
    }

    #[Route(path: '/k/{token}/export/{exportId}', name: 'calendar_client_export_status', requirements: ['token' => ClientCalendarController::TOKEN_REQUIREMENT, 'exportId' => Calendar::ID_REQUIREMENT], methods: ['GET'])]
    public function status(string $token, string $exportId): Response
    {
        [$calendar] = $this->access->forToken($token);

        return $this->overRead($token) ?? $this->http->status($calendar, $exportId, $token);
    }

    #[Route(path: '/k/{token}/export/{exportId}/download', name: 'calendar_client_export_download', requirements: ['token' => ClientCalendarController::TOKEN_REQUIREMENT, 'exportId' => Calendar::ID_REQUIREMENT], methods: ['GET'])]
    public function download(string $token, string $exportId): Response
    {
        [$calendar] = $this->access->forToken($token);

        return $this->overRead($token) ?? $this->http->download($calendar, $exportId);
    }

    public static function limiterKey(string $token): string
    {
        return hash('xxh128', $token);
    }

    private function overRead(string $token): null|Response
    {
        return CalendarResponses::overBudget($this->readLimiter->create(self::limiterKey($token))->consume(), ClientCalendarHistoryController::TOO_MANY_REQUESTS);
    }
}
