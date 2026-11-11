<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Calendar;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Services\Calendar\CalendarAccess;
use WBoost\Web\Services\Calendar\CalendarAssetUploader;

/** The upload endpoint of the client link — kinds `photo` and `icon` only. */
final class UploadClientCalendarAssetController extends AbstractController
{
    public function __construct(
        readonly private CalendarAccess $access,
        readonly private CalendarAssetUploader $uploader,
    ) {
    }

    #[Route(path: '/k/{token}/asset', name: 'calendar_client_asset', requirements: ['token' => ClientCalendarController::TOKEN_REQUIREMENT], methods: ['POST'])]
    public function __invoke(string $token, Request $request): Response
    {
        [$calendar, $actor] = $this->access->forToken($token);

        return $this->uploader->upload($request, $calendar, $actor);
    }
}
