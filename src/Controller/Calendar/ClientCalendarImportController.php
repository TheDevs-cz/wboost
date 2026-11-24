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
use WBoost\Web\Services\Calendar\CalendarImportHttp;
use WBoost\Web\Services\Calendar\CalendarResponses;

/**
 * „Nahrát z Excelu“ for the holder of the client link (K10b). Reading a
 * table costs one upload of the link's upload budgets (the same two limiters
 * as {@see UploadClientCalendarAssetController}) — a file is parsed, even
 * though nothing is stored. An unknown token is a 404 before anything is counted.
 */
final class ClientCalendarImportController extends AbstractController
{
    public function __construct(
        readonly private CalendarAccess $access,
        readonly private CalendarImportHttp $http,
        #[Autowire(service: 'limiter.calendar_client_upload')]
        readonly private RateLimiterFactoryInterface $uploadLimiter,
        #[Autowire(service: 'limiter.calendar_client_upload_link')]
        readonly private RateLimiterFactoryInterface $linkLimiter,
    ) {
    }

    #[Route(path: '/k/{token}/import/table', name: 'calendar_client_import_table', requirements: ['token' => ClientCalendarController::TOKEN_REQUIREMENT], methods: ['POST'])]
    public function table(string $token, Request $request): Response
    {
        $this->access->forToken($token);

        $refusal = CalendarResponses::overBudget($this->uploadLimiter->create(UploadClientCalendarAssetController::limiterKey($token, $request->getClientIp()))->consume(), UploadClientCalendarAssetController::TOO_MANY_UPLOADS)
            ?? CalendarResponses::overBudget($this->linkLimiter->create(UploadClientCalendarAssetController::linkLimiterKey($token))->consume(), UploadClientCalendarAssetController::TOO_MANY_UPLOADS);

        return $refusal ?? $this->http->table($request);
    }

    #[Route(path: '/k/{token}/import/template/{kind}', name: 'calendar_client_import_template', requirements: ['token' => ClientCalendarController::TOKEN_REQUIREMENT, 'kind' => CalendarImportController::KIND_REQUIREMENT], methods: ['GET'])]
    public function template(string $token, string $kind): Response
    {
        [$calendar] = $this->access->forToken($token);

        return $this->http->template($calendar, $kind);
    }
}
