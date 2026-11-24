<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use WBoost\Web\Entity\Calendar;

/**
 * The two endpoints of „Nahrát z Excelu“ (K10b), shared by the app routes
 * (designer + read-only member) and the client link:
 * - `table` — POST `_token` + `file` → `{ sheets: [{ name, rows }], truncated, name }`
 *   ({@see CalendarTableReader}); the file is read from PHP's upload temp
 *   file and never stored;
 * - `template` — GET the „Stáhnout vzor“ `.xlsx` ({@see CalendarImportTemplate}).
 * Importing is filling content, which a client may do — so there is no role
 * check here; the writes that follow are ordinary content ops.
 */
final readonly class CalendarImportHttp
{
    public function __construct(
        private CalendarTableReader $reader,
        private CalendarImportTemplate $template,
        private CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    public function table(Request $request): JsonResponse
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(CalendarDocument::CSRF_TOKEN_ID, $request->request->getString('_token')))) {
            return CalendarResponses::csrfExpired();
        }

        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return CalendarResponses::error('Vyberte soubor.', Response::HTTP_BAD_REQUEST);
        }

        if ((int) $file->getSize() > CalendarDocument::MAX_FILE_SIZE) {
            return CalendarResponses::error(CalendarAssetUploader::TOO_LARGE);
        }

        $fileName = basename(str_replace('\\', '/', CalendarText::singleLine($file->getClientOriginalName())));

        return CalendarResponses::guard(fn (): JsonResponse => new JsonResponse(
            $this->reader->read($file->getPathname(), $fileName) + ['name' => mb_substr($fileName, 0, 120)],
        ));
    }

    public function template(Calendar $calendar, string $kind): Response
    {
        $response = new Response($this->template->build($calendar, $kind), Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store',
        ]);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $this->template->fileName($calendar, $kind)));

        return $response;
    }
}
