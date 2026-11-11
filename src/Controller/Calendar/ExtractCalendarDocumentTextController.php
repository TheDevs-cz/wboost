<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Calendar;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Services\Calendar\CalendarAssetUploader;
use WBoost\Web\Services\Calendar\CalendarDocument;
use WBoost\Web\Services\Calendar\CalendarResponses;
use WBoost\Web\Services\Calendar\ExtractDocumentText;
use WBoost\Web\Services\Security\CalendarVoter;

/**
 * "Text z Wordu": reads the plain text of an uploaded `.docx` / `.txt` and
 * answers `{ text, name }`. Nothing is stored — the workspace puts the text
 * into a special page item and saves it like any other content.
 */
final class ExtractCalendarDocumentTextController extends AbstractController
{
    public function __construct(
        readonly private ExtractDocumentText $extractDocumentText,
    ) {
    }

    #[Route(path: '/calendar/{calendarId}/document-text', name: 'calendar_document_text', requirements: ['calendarId' => Calendar::ID_REQUIREMENT], methods: ['POST'])]
    #[IsGranted(CalendarVoter::EDIT, 'calendar')]
    public function __invoke(
        #[MapEntity(id: 'calendarId')]
        Calendar $calendar,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid(CalendarDocument::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            return CalendarResponses::csrfExpired();
        }

        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return CalendarResponses::error('Vyberte soubor.', Response::HTTP_BAD_REQUEST);
        }

        if ($file->getSize() > CalendarDocument::MAX_FILE_SIZE) {
            return CalendarResponses::error(CalendarAssetUploader::TOO_LARGE);
        }

        $fileName = basename(str_replace('\\', '/', $file->getClientOriginalName()));

        return CalendarResponses::guard(fn (): JsonResponse => new JsonResponse([
            'text' => $this->extractDocumentText->extract($file->getContent(), $fileName),
            'name' => mb_substr(pathinfo($fileName, PATHINFO_FILENAME), 0, 60),
        ]));
    }
}
