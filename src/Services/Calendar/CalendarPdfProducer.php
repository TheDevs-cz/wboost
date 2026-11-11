<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Entity\CalendarAsset;
use WBoost\Web\Exceptions\CalendarExportNeedsMoreSpace;
use WBoost\Web\Exceptions\PdfStructureNotSupported;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Repository\CalendarAssetRepository;
use WBoost\Web\Services\Print\PdfChunkMerger;
use WBoost\Web\Services\Print\PdfPageBoxes;
use WBoost\Web\Value\Calendar\CalendarExportVariant;
use WBoost\Web\Value\Calendar\CalendarPdfPlan;

/**
 * The whole calendar as one PDF file
 * (docs/architecture/calendars.md § "PDF export"):
 *
 * plan (page list + sheet size, from the calendar's JavaScript) → render the
 * pages in **chunks** (each an HTML document with only its own photos) →
 * copy the chunk files into one, giving every page its boxes.
 */
readonly final class CalendarPdfProducer implements CalendarPdfProducerInterface
{
    /** Pages of one chunk at most — bounds the HTML Chromium loads and the time of one Gotenberg call. */
    public const int CHUNK_PAGES = 6;

    /**
     * Bytes of page-bound pictures in one chunk at most (a single page above
     * it is a chunk of its own). A decoded 60 MP photo is 240 MB inside
     * Chromium, so heavy pages must not pile up in one document.
     */
    public const int CHUNK_BYTES = 16_000_000;

    private const int RENDER_ATTEMPTS = 3;

    private const int WORK_SPACE_MARGIN_BYTES = 200_000_000;

    public function __construct(
        private CalendarAssetRepository $assetRepository,
        private CalendarPdfRenderer $renderer,
        private CalendarPdfMerger $merger,
        private CalendarRenderAssets $renderAssets,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function produce(Calendar $calendar, CalendarExportVariant $variant, string $directory, callable $progress): string
    {
        $plan = $this->renderer->plan($calendar, $variant);

        if ($plan->pageKeys === []) {
            throw new \RuntimeException('The calendar has no pages.');
        }

        $progress(0, $plan->pageCount());

        $assets = $this->assetRepository->forCalendar($calendar->id);
        $this->assertWorkSpace($calendar, $assets, $variant, $directory);
        $paths = [];
        $done = 0;
        $started = microtime(true);
        $slowest = 0.0;

        foreach (self::chunks($plan->pageKeys, $this->pageBytes($calendar, $assets, $variant)) as $index => $pageKeys) {
            $chunkStarted = microtime(true);
            $paths[] = $this->renderChunk($calendar, $plan, $pageKeys, $directory, sprintf('chunk-%04d', $index), $assets);
            $slowest = max($slowest, microtime(true) - $chunkStarted);
            $done += count($pageKeys);
            $progress($done, $plan->pageCount());
        }

        $rendered = microtime(true);
        $merged = $this->assemble($calendar, $plan, $paths, $directory);
        // a heartbeat: the file is whole, what follows (the upload) is the caller's
        $progress($plan->pageCount(), $plan->pageCount());

        $this->logger->info('Calendar PDF export: file produced.', [
            'calendarId' => $calendar->id->toString(),
            'variant' => $variant->value,
            'pages' => $plan->pageCount(),
            'chunks' => count($paths),
            'renderSeconds' => round($rendered - $started, 2),
            'slowestChunkSeconds' => round($slowest, 2),
            'mergeSeconds' => round(microtime(true) - $rendered, 2),
            'bytes' => (int) filesize($merged),
        ]);

        return $merged;
    }

    /**
     * The one hard ceiling left is the disk: the chunk files and then the
     * merged file lie in the work directory — for a moment both. A print
     * export of a year at the upload cap is ~530 MB, so ~1.1 GB. Better to
     * say so before two minutes of rendering than to fail at the end.
     *
     * @param list<CalendarAsset> $assets
     *
     * @throws CalendarExportNeedsMoreSpace
     */
    private function assertWorkSpace(Calendar $calendar, array $assets, CalendarExportVariant $variant, string $directory): void
    {
        if ($variant !== CalendarExportVariant::Print) {
            // previews: tens of MB at most
            return;
        }

        $shown = $this->renderAssets->shared($calendar);

        foreach ($this->renderAssets->perPage($calendar) as $ids) {
            $shown += $ids;
        }

        $bytes = 0;

        foreach ($assets as $asset) {
            if (isset($shown[strtolower($asset->id->toString())])) {
                $bytes += $asset->sizeBytes;
            }
        }

        $needed = 2 * $bytes + self::WORK_SPACE_MARGIN_BYTES;
        $free = @disk_free_space($directory);

        if ($free !== false && $free < $needed) {
            throw new CalendarExportNeedsMoreSpace($needed, (int) $free);
        }
    }

    /**
     * Chunk files → the one file, with its page boxes and Info.
     *
     * {@see PdfChunkMerger} copies the chunks together on the local disk —
     * constant memory, no size limit, a second per 500 MB. Should Chromium
     * ever write a structure it does not read, Gotenberg's merge route (qpdf)
     * + {@see PdfPageBoxes} do the same job for files small enough to travel
     * there and back.
     *
     * @param list<string> $paths
     */
    private function assemble(Calendar $calendar, CalendarPdfPlan $plan, array $paths, string $directory): string
    {
        $boxes = [
            'width' => CalendarPdfPlan::pt($plan->sheetWidth),
            'height' => CalendarPdfPlan::pt($plan->sheetHeight),
            'trimInset' => CalendarPdfPlan::pt($plan->trimInset),
            'bleedInset' => CalendarPdfPlan::pt($plan->bleedInset),
        ];
        $info = ['title' => $calendar->name, 'creator' => 'WBoost', 'producer' => 'WBoost (Chromium)', 'createdAt' => $this->clock->now()];
        $target = $directory . '/merged.pdf';

        try {
            PdfChunkMerger::merge($paths, $target, $boxes, $info);

            foreach ($paths as $path) {
                // the work directory must not hold a 500 MB calendar twice while it is uploaded
                @unlink($path);
            }

            return $target;
        } catch (PdfStructureNotSupported $unsupported) {
            $this->logger->warning('Calendar PDF export: chunks merged through Gotenberg instead of locally.', [
                'calendarId' => $calendar->id->toString(),
                'reason' => $unsupported->getMessage(),
            ]);
        }

        $merged = $this->merger->merge($paths, $directory);

        try {
            PdfPageBoxes::apply($merged, $boxes['width'], $boxes['height'], $boxes['trimInset'], $boxes['bleedInset'], $info);
        } catch (PdfStructureNotSupported $unsupported) {
            // The file is complete and printable; it only keeps Chromium's page size
            // (≤ 1 pt larger) and has no TrimBox / BleedBox.
            $this->logger->warning('Calendar PDF export: page boxes not set.', [
                'calendarId' => $calendar->id->toString(),
                'reason' => $unsupported->getMessage(),
            ]);
        }

        return $merged;
    }

    /**
     * Pages → chunks: consecutive pages, at most {@see CHUNK_PAGES} of them
     * and at most {@see CHUNK_BYTES} of their own pictures.
     *
     * @param list<string> $pageKeys
     * @param array<string, int> $pageBytes page key → bytes of the pictures only that page shows
     *
     * @return list<list<string>>
     */
    public static function chunks(array $pageKeys, array $pageBytes, int $maxPages = self::CHUNK_PAGES, int $maxBytes = self::CHUNK_BYTES): array
    {
        $chunks = [];
        $chunk = [];
        $bytes = 0;

        foreach ($pageKeys as $pageKey) {
            $own = $pageBytes[$pageKey] ?? 0;

            if ($chunk !== [] && (count($chunk) >= $maxPages || $bytes + $own > $maxBytes)) {
                $chunks[] = $chunk;
                $chunk = [];
                $bytes = 0;
            }

            $chunk[] = $pageKey;
            $bytes += $own;
        }

        if ($chunk !== []) {
            $chunks[] = $chunk;
        }

        return $chunks;
    }

    /**
     * @param list<string> $pageKeys
     * @param list<CalendarAsset> $assets
     */
    private function renderChunk(Calendar $calendar, CalendarPdfPlan $plan, array $pageKeys, string $directory, string $name, array $assets): string
    {
        for ($attempt = 1; ; ++$attempt) {
            try {
                return $this->renderer->renderChunk($calendar, $plan, $pageKeys, $directory, $name, $assets);
            } catch (TemplateRenderUnavailable $busy) {
                // Gotenberg is shared with every preview of the app; a busy moment is not a failed export.
                if ($attempt >= self::RENDER_ATTEMPTS) {
                    throw $busy;
                }

                sleep(2 * $attempt);
            }
        }
    }

    /**
     * @param list<CalendarAsset> $assets
     *
     * @return array<string, int>
     */
    private function pageBytes(Calendar $calendar, array $assets, CalendarExportVariant $variant): array
    {
        if ($variant !== CalendarExportVariant::Print) {
            // previews are a few hundred kB each: the page count alone bounds a chunk
            return [];
        }

        $sizes = [];

        foreach ($assets as $asset) {
            $sizes[strtolower($asset->id->toString())] = $asset->sizeBytes;
        }

        $bytes = [];

        foreach ($this->renderAssets->perPage($calendar) as $pageKey => $ids) {
            $bytes[$pageKey] = array_sum(array_intersect_key($sizes, $ids));
        }

        return $bytes;
    }
}
