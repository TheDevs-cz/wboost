<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\TemplateDocument;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Services\ReleaseSessionLock;
use WBoost\Web\Services\Security\TemplateDocumentVoter;
use WBoost\Web\Services\TemplateDocument\DocumentExportArchive;
use WBoost\Web\Services\TemplateDocument\RecordDocumentExportVersion;
use WBoost\Web\Services\TemplateDocument\TemplateDocumentExporter;
use WBoost\Web\Services\TemplateGroup\GroupFillRenderer;
use WBoost\Web\Services\Usage\RecordExportUsage;
use WBoost\Web\Value\DocumentFillKeys;
use WBoost\Web\Value\DocumentFillValues;
use WBoost\Web\Value\ExportChannel;
use WBoost\Web\Value\ExportFormat;

/**
 * Exports the whole filled document as ONE file — the pressed button posts
 * `format`: `pdf` (multi-page print PDF, print formats only), `pdf-screen`
 * ("PDF k odeslání") or `png` (ZIP). The form is the fill page's, in
 * FILL-KEY space ({@see DocumentFillKeys}); every page is translated back to
 * its real inputIds and resolved LENIENTLY (the web policy), then rendered.
 *
 * Buffered response on purpose — a flushing StreamedResponse corrupts the
 * next request under FrankenPHP's resident process.
 */
final class TemplateDocumentExportController extends AbstractController
{
    /** Seconds — a 30-page print PDF is ~2 renders + a CMYK pass per page. */
    private const int TIME_LIMIT = 600;

    public function __construct(
        readonly private GroupFillRenderer $groupFillRenderer,
        readonly private TemplateDocumentExporter $exporter,
        readonly private DocumentExportArchive $archive,
        readonly private RecordExportUsage $recordExportUsage,
        readonly private ReleaseSessionLock $releaseSessionLock,
        readonly private RecordDocumentExportVersion $recordExportVersion,
    ) {
    }

    /**
     * GET only sends the visitor back to the fill page (a failed export used
     * to leave the export URL in the address bar — see the group twin).
     */
    #[Route(path: '/template-document/{documentId}/export', name: 'template_document_export', methods: ['GET', 'POST'])]
    #[IsGranted(TemplateDocumentVoter::VIEW, 'document')]
    public function __invoke(
        #[MapEntity(id: 'documentId')]
        TemplateDocument $document,
        Request $request,
    ): Response {

        if (!$request->isMethod(Request::METHOD_POST)) {
            return $this->redirectToRoute('template_document_fill', ['documentId' => $document->id]);
        }

        $pages = $document->pages();

        if ($pages === []) {
            return $this->renderFailed($document, 'Dokument nemá žádné stránky.', Response::HTTP_BAD_REQUEST);
        }

        $format = ExportFormat::fromRequestValue($request->request->get('format'));

        if ($format === ExportFormat::Pdf && !$document->dimension->isPrintFormat()) {
            return $this->renderFailed($document, 'Tiskové PDF je k dispozici jen pro tiskové formáty (mm / cm). Použijte PDF k odeslání.', Response::HTTP_BAD_REQUEST);
        }

        $raw = [
            'textValues' => $request->request->all('textValues'),
            'hiddenValues' => $request->request->all('hiddenValues'),
            'fontValues' => $request->request->all('fontValues'),
            'images' => $request->request->all('images'),
            'shapeColorValues' => $request->request->all('shapeColorValues'),
            'imagePlacements' => $request->request->all('imagePlacements'),
        ];

        // The longest request this app makes: off the session lock, and a
        // time budget that fits a full 30-page print PDF.
        $this->releaseSessionLock->release($request);
        set_time_limit(self::TIME_LIMIT);

        $keys = DocumentFillKeys::forDocument($document);

        try {
            $resolved = [];

            foreach ($pages as $page) {
                $values = $keys->valuesForPage($page, $raw);
                [$overrides, $imageOverrides] = $this->groupFillRenderer->resolve(
                    $page->variant,
                    $values['textValues'],
                    $values['hiddenValues'],
                    $values['images'],
                    $values['imagePlacements'],
                    $values['fontValues'],
                    $values['shapeColorValues'],
                );
                $resolved[] = [$page, $overrides, $imageOverrides];
            }

            $bytes = $this->exporter->export($document, $format, $resolved, strictContainerOverflow: false);
        } catch (TemplateRenderUnavailable) {
            return $this->renderFailed($document, 'Vykreslovací služba je přetížená a neodpověděla včas. Zkuste export prosím znovu za chvíli.', Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (BadRequestHttpException $e) {
            // The user's values are not renderable (an unreadable image, a
            // refused value): a page they can go BACK from, form still filled.
            return $this->renderFailed($document, $e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        foreach ($pages as $page) {
            $this->recordExportUsage->record($page->variant, ExportChannel::Web, $format);
        }

        // Re-loadable from "Historie exportů"; a loaded draft is promoted.
        $this->recordExportVersion->record(
            $document,
            ExportChannel::Web,
            DocumentFillValues::fromWebForm($document, $raw),
            $request->request->getString('draftVersionId'),
        );

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => $this->archive->contentType($format),
            'Content-Disposition' => sprintf('attachment; filename="%s"', $this->archive->fileName($document, $format)),
            'Cache-Control' => 'no-store',
        ]);
    }

    private function renderFailed(TemplateDocument $document, string $reason, int $status): Response
    {
        return $this->render('export_failed.html.twig', [
            'project' => $document->project,
            'menu_item' => 'templates',
            'reason' => $reason,
            'back_url' => $this->generateUrl('template_document_fill', ['documentId' => $document->id]),
        ], new Response(status: $status));
    }
}
