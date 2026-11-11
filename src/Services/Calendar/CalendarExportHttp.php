<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Entity\CalendarExport;
use WBoost\Web\Exceptions\CalendarExportNotFound;
use WBoost\Web\Repository\CalendarExportRepository;
use WBoost\Web\Value\Calendar\CalendarActor;
use WBoost\Web\Value\Calendar\CalendarExportVariant;

/**
 * The three export endpoints behind both route families — the designer's
 * `/calendar/{id}/export…` and the client link's `/k/{token}/export…`
 * (docs/architecture/calendars.md § "PDF export"): ask for a PDF, poll the
 * job, download the file. The controllers only resolve the calendar, the
 * role and (on the public family) the budget.
 *
 * An export is always looked up **inside its calendar**
 * ({@see CalendarExportRepository::getOfCalendar()}): an id that belongs to
 * another calendar is a 404, whatever the caller may open elsewhere.
 */
readonly final class CalendarExportHttp
{
    public const string UNKNOWN_VARIANT = 'Neznámá varianta exportu.';
    public const string NOT_READY = 'PDF ještě není připravené.';
    public const string FILE_MISSING = 'Soubor už není k dispozici. Spusťte export znovu.';

    public function __construct(
        private CalendarExports $exports,
        private CalendarExportRepository $exportRepository,
        private Filesystem $filesystem,
        private UrlGeneratorInterface $urlGenerator,
        private CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    public function variant(Request $request): null|CalendarExportVariant
    {
        return CalendarExportVariant::tryFrom($request->request->getString('variant'));
    }

    public function csrfValid(Request $request): bool
    {
        return $this->csrfTokenManager->isTokenValid(new CsrfToken(CalendarDocument::CSRF_TOKEN_ID, $request->request->getString('_token')));
    }

    /**
     * POST: `{ export }` — a new job, or the export that already answers the request.
     *
     * @param null|string $token the client-link token when the request came through `/k/{token}`
     */
    public function request(Calendar $calendar, CalendarExportVariant $variant, CalendarActor $actor, null|string $token = null): JsonResponse
    {
        return CalendarResponses::guard(fn (): JsonResponse => new JsonResponse([
            'export' => $this->describe($this->exports->request($calendar, $variant, $actor), $token),
        ]));
    }

    /**
     * GET: `{ export }`.
     *
     * @throws CalendarExportNotFound
     */
    public function status(Calendar $calendar, string $exportId, null|string $token = null): JsonResponse
    {
        return new JsonResponse(['export' => $this->describe($this->find($calendar, $exportId), $token)]);
    }

    /**
     * GET: the file. Buffered up to {@see CalendarExports::BUFFERED_DOWNLOAD_BYTES},
     * a redirect to the storage above it — never a streamed response
     * (FrankenPHP: a flushing `StreamedResponse` corrupts the next request).
     *
     * @throws CalendarExportNotFound
     */
    public function download(Calendar $calendar, string $exportId): Response
    {
        $export = $this->find($calendar, $exportId);

        if (!$export->isDownloadable() || $export->path === null) {
            return CalendarResponses::error(self::NOT_READY, Response::HTTP_CONFLICT);
        }

        if (!$this->exports->isBuffered($export)) {
            // The object was stored with its own Content-Disposition (CalendarPdfExporter).
            return new RedirectResponse($this->exports->storageUrl($export), Response::HTTP_FOUND, ['Cache-Control' => 'private, no-store']);
        }

        try {
            $bytes = $this->filesystem->read($export->path);
        } catch (FilesystemException) {
            return CalendarResponses::error(self::FILE_MISSING, Response::HTTP_GONE);
        }

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => CalendarExports::fileName($calendar, $export->variant)->disposition(),
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(CalendarExport $export, null|string $token = null): array
    {
        $id = $export->id->toString();
        $route = static fn (string $name): array => $token !== null
            ? ['calendar_client_export_' . $name, ['token' => $token, 'exportId' => $id]]
            : ['calendar_export_' . $name, ['calendarId' => $export->calendar->id->toString(), 'exportId' => $id]];

        return $this->exports->describe(
            $export,
            fn (): string => $this->urlGenerator->generate(...$route('status')),
            fn (): string => $this->urlGenerator->generate(...$route('download')),
        );
    }

    /**
     * @throws CalendarExportNotFound
     */
    private function find(Calendar $calendar, string $exportId): CalendarExport
    {
        if (!Uuid::isValid($exportId)) {
            throw new CalendarExportNotFound();
        }

        return $this->exportRepository->getOfCalendar($calendar->id, Uuid::fromString($exportId));
    }
}
