<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Calendar;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Services\Calendar\CalendarAccess;
use WBoost\Web\Services\Calendar\CalendarAssetUploader;
use WBoost\Web\Services\Security\CalendarVoter;

/**
 * Uploads one calendar file (`_token`, `kind`, `file`) and answers its id,
 * URLs and pixel size (docs/calendar/architecture.md §C.7).
 *
 * The cap is the app-wide 10 MB DECIMAL (`maxSize: '10m'`), mirrored for the
 * calendar JavaScript by `env.maxFileSize = 10000000`
 * ({@see \WBoost\Web\Services\Calendar\CalendarDocument::MAX_FILE_SIZE}) —
 * see docs/architecture/gallery-and-uploads.md.
 */
final class UploadCalendarAssetController extends AbstractController
{
    public const string MAX_SIZE = '10m';

    public function __construct(
        readonly private CalendarAccess $access,
        readonly private CalendarAssetUploader $uploader,
    ) {
    }

    #[Route(path: '/calendar/{calendarId}/asset', name: 'calendar_asset_upload', requirements: ['calendarId' => Calendar::ID_REQUIREMENT], methods: ['POST'])]
    #[IsGranted(CalendarVoter::VIEW, 'calendar')]
    public function __invoke(
        #[MapEntity(id: 'calendarId')]
        Calendar $calendar,
        Request $request,
    ): Response {
        return $this->uploader->upload($request, $calendar, $this->access->forUser($calendar));
    }
}
