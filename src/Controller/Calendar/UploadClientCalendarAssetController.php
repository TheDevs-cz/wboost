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
use WBoost\Web\Services\Calendar\CalendarAssetUploader;
use WBoost\Web\Services\Calendar\CalendarResponses;

/**
 * The upload endpoint of the client link — raster photos only (kind `photo`;
 * {@see \WBoost\Web\Value\Calendar\CalendarAssetKind::clientMayUpload()}).
 *
 * The route is public: whoever holds the link stores files with no account.
 * Uploads are therefore rate limited twice — per link and address
 * (`calendar_client_upload`) and per link whatever the address
 * (`calendar_client_upload_link`, config/packages/rate_limiter.php) — and the
 * total a calendar holds from clients is capped by the uploader. Over a
 * limit the answer is a 429 in the calendar endpoints' JSON error shape, so
 * the client page shows the Czech sentence like any other refusal. The limiter
 * is consumed by hand rather than through `#[RateLimit]`, whose 429 is the
 * kernel's generic error page.
 */
final class UploadClientCalendarAssetController extends AbstractController
{
    public const string TOO_MANY_UPLOADS = 'Nahráli jste příliš mnoho souborů najednou. Zkuste to prosím za chvíli znovu.';

    public function __construct(
        readonly private CalendarAccess $access,
        readonly private CalendarAssetUploader $uploader,
        #[Autowire(service: 'limiter.calendar_client_upload')]
        readonly private RateLimiterFactoryInterface $uploadLimiter,
        #[Autowire(service: 'limiter.calendar_client_upload_link')]
        readonly private RateLimiterFactoryInterface $linkLimiter,
    ) {
    }

    #[Route(path: '/k/{token}/asset', name: 'calendar_client_asset', requirements: ['token' => ClientCalendarController::TOKEN_REQUIREMENT], methods: ['POST'])]
    public function __invoke(string $token, Request $request): Response
    {
        // An unknown token is a 404 before anything is counted.
        [$calendar, $actor] = $this->access->forToken($token);

        // The link-wide budget is only charged for a request the address
        // budget let through.
        $refusal = CalendarResponses::overBudget($this->uploadLimiter->create(self::limiterKey($token, $request->getClientIp()))->consume(), self::TOO_MANY_UPLOADS)
            ?? CalendarResponses::overBudget($this->linkLimiter->create(self::linkLimiterKey($token))->consume(), self::TOO_MANY_UPLOADS);

        if ($refusal !== null) {
            return $refusal;
        }

        return $this->uploader->upload($request, $calendar, $actor);
    }

    /** One budget per client link and address — a second office behind another address is not blocked by the first. */
    public static function limiterKey(string $token, null|string $clientIp): string
    {
        return hash('xxh128', $token . '|' . ($clientIp ?? ''));
    }

    /** One budget per client link, whatever the address. */
    public static function linkLimiterKey(string $token): string
    {
        return hash('xxh128', $token);
    }
}
