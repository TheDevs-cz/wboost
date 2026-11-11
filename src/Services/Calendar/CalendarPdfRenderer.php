<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use Sensiolabs\GotenbergBundle\Exception\ClientException;
use Sensiolabs\GotenbergBundle\GotenbergPdfInterface;
use Sensiolabs\GotenbergBundle\Processor\FileProcessor;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Twig\Environment;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Entity\CalendarAsset;
use WBoost\Web\Exceptions\CalendarModelRefused;
use WBoost\Web\Exceptions\CalendarModelUnavailable;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Services\Editor\TemplateVariantImageRenderer;
use WBoost\Web\Value\Calendar\CalendarExportVariant;
use WBoost\Web\Value\Calendar\CalendarPdfPlan;

/**
 * Pages of a calendar as a VECTOR PDF: the calendar's own page SVG printed by
 * Gotenberg's Chromium (docs/architecture/calendars.md § "PDF export").
 *
 * Printed, not screenshotted — that is the whole point: Chromium's PDF
 * backend writes the SVG's texts as text with the project fonts embedded,
 * its shapes as paths and its `<image>`s as image objects with the pixels of
 * the file they came from. Nothing is rasterised at a "render resolution",
 * so there is none to choose.
 *
 * A year is 55+ pages with a photo of up to 10 MB each, so it is never one
 * HTML document: the caller renders **chunks** of a few pages
 * ({@see renderChunk()}), each carrying only its own pictures, and merges the
 * chunk files ({@see CalendarPdfMerger}). The page size comes from the
 * calendar's JavaScript ({@see CalendarModelBridge}: `pageViewBox`), never
 * from PHP.
 *
 * Works on FILES — a chunk can weigh tens of MB.
 */
final class CalendarPdfRenderer
{
    public function __construct(
        private readonly GotenbergPdfInterface $gotenberg,
        private readonly CalendarModelBridge $model,
        private readonly CalendarModuleInliner $modules,
        private readonly CalendarDocument $document,
        private readonly CalendarRenderAssets $assets,
        private readonly Environment $twig,
    ) {
    }

    /**
     * @throws CalendarModelRefused `no_layout`
     * @throws CalendarModelUnavailable
     */
    public function plan(Calendar $calendar, CalendarExportVariant $variant): CalendarPdfPlan
    {
        $design = $this->model->run($calendar)->design;

        $print = $design->print ?? null;
        $bleedMm = $print instanceof \stdClass && ($print->bleed ?? false) === true && is_numeric($print->bleedMm ?? null) ? (float) $print->bleedMm : 0.0;
        // What step 5 shows the designer: the bleed when it is on, the marks when they are on.
        $bleed = $variant === CalendarExportVariant::Print && $bleedMm > 0;
        $marks = $variant === CalendarExportVariant::Print && $print instanceof \stdClass && ($print->marks ?? false) === true;

        $model = $this->model->run($calendar, pages: true, view: ['bleed' => $bleed, 'marks' => $marks]);

        if (!$model->hasLayout() || $model->view === null) {
            throw new CalendarModelRefused('no_layout', 'The calendar has no layout yet, so there is nothing to export.');
        }

        $view = $model->view;
        // The view box starts at the negative margin around the trim (0, 0).
        $trimInset = max(0.0, -$view['x']);

        return new CalendarPdfPlan(
            variant: $variant,
            pageKeys: array_column($model->pages ?? [], 'key'),
            sheetWidth: round($view['w'], 3),
            sheetHeight: round($view['h'], 3),
            trimInset: round($trimInset, 3),
            bleedInset: round(max(0.0, $trimInset - ($bleed || $marks ? $bleedMm : 0.0)), 3),
            bleed: $bleed,
            marks: $marks,
            fontFaces: $this->assets->fontFaces($calendar, $model->design),
            title: $calendar->name,
        );
    }

    /**
     * A PDF file of these pages, one PDF page each, in the given order.
     *
     * The HTML document is written to a temp file piece by piece, the
     * uploaded photos streamed into it straight from the storage, and sent
     * to Gotenberg as a file; the answer is written to a file too. PHP's
     * memory stays flat whatever the photos weigh.
     *
     * @param list<string> $pageKeys
     * @param string $directory an existing directory for the work files and the result
     * @param string $name file name of the result, without the extension
     * @param null|list<CalendarAsset> $assets the calendar's assets when the caller already has them
     *
     * @return string absolute path of the PDF
     *
     * @throws TemplateRenderUnavailable the renderer is busy or down
     */
    public function renderChunk(Calendar $calendar, CalendarPdfPlan $plan, array $pageKeys, string $directory, string $name, null|array $assets = null): string
    {
        $deferred = [];
        $boot = [
            'calendar' => $this->document->calendar($calendar),
            'assets' => $this->assets->inlined($calendar, $pageKeys, $plan->variant->pictures(), $assets, $deferred),
            'env' => [
                'fonts' => $this->document->fonts($calendar->project),
                'palette' => $this->document->palette($calendar->project),
                'currentYear' => $this->document->currentYear(),
            ],
            'pageKeys' => $pageKeys,
            'bleed' => $plan->bleed,
            'marks' => $plan->marks,
            // Paper gets nothing invented: an empty photo frame stays empty in the print file.
            // The approval file shows the grey frame, as the screen does — "a photo is missing here".
            'placeholders' => $plan->variant === CalendarExportVariant::Print ? 'none' : 'frames',
        ];

        $html = $this->twig->render('calendar/headless_print.html.twig', [
            'import_map' => $this->modules->importMap(),
            'boot' => CalendarDocument::encode($boot),
            'font_faces' => json_encode($plan->fontFaces, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES),
            // whole points, rounded UP: see CalendarPdfPlan
            'page_width' => (int) ceil(CalendarPdfPlan::pt($plan->sheetWidth) - 0.001),
            'page_height' => (int) ceil(CalendarPdfPlan::pt($plan->sheetHeight) - 0.001),
            'sheet_width' => self::mm($plan->sheetWidth),
            'sheet_height' => self::mm($plan->sheetHeight),
            'title' => $plan->title,
        ]);
        unset($boot);

        $source = sprintf('%s/%s.html', $directory, $name);
        $this->writeDocument($source, $html, $deferred);
        unset($html);

        try {
            $file = $this->gotenberg->html()
                ->contentFile($source)
                ->preferCssPageSize()
                ->printBackground()
                ->waitForExpression('window.calendarRendered === true')
                ->failOnConsoleExceptions()
                ->fileName($name)
                ->generate()
                ->processor(new FileProcessor(new Filesystem(), $directory))
                ->process();
        } catch (TransportExceptionInterface $exception) {
            throw TemplateRenderUnavailable::timedOut($exception);
        } catch (ClientException $exception) {
            if (TemplateVariantImageRenderer::isRendererOverloaded($exception)) {
                throw TemplateRenderUnavailable::timedOut($exception);
            }

            throw new \RuntimeException('The calendar pages could not be rendered: ' . mb_substr(CalendarPageRenderer::errorBody($exception), 0, 400), 0, $exception);
        } finally {
            @unlink($source);
        }

        if (!$file instanceof \SplFileInfo) {
            throw new \RuntimeException('The calendar PDF render came back empty.');
        }

        $path = $file->getPathname();
        $head = (string) @file_get_contents($path, false, null, 0, 5);

        if ($head !== '%PDF-') {
            throw new \RuntimeException('The calendar PDF render came back empty.');
        }

        return $path;
    }

    /**
     * @param array<string, CalendarAsset> $deferred token → the asset whose file replaces it
     */
    private function writeDocument(string $path, string $html, array $deferred): void
    {
        $out = fopen($path, 'wb');

        if ($out === false) {
            throw new \RuntimeException('The calendar PDF work file cannot be written.');
        }

        try {
            $offset = 0;

            while ($deferred !== [] && preg_match('/@@wbcal-asset:[0-9a-f-]{36}@@/', $html, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
                [$token, $at] = $match[0];
                fwrite($out, substr($html, $offset, $at - $offset));
                $offset = $at + strlen($token);

                if (isset($deferred[$token])) {
                    // a missing file leaves an empty URL: the frame is drawn without its picture
                    $this->assets->writeDataUri($deferred[$token], $out);
                }
            }

            fwrite($out, substr($html, $offset));
        } finally {
            fclose($out);
        }
    }

    /** A length for CSS: a plain decimal, never an exponent or a locale comma. */
    private static function mm(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
