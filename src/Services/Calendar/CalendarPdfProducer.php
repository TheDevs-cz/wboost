<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Entity\CalendarAsset;
use WBoost\Web\Exceptions\PdfStructureNotSupported;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Repository\CalendarAssetRepository;
use WBoost\Web\Services\Print\PdfPageBoxes;
use WBoost\Web\Value\Calendar\CalendarExportVariant;
use WBoost\Web\Value\Calendar\CalendarPdfPlan;

/**
 * The whole calendar as one PDF file
 * (docs/architecture/calendars.md § "PDF export"):
 *
 * plan (page list + sheet size, from the calendar's JavaScript) → render the
 * pages in **chunks** (each an HTML document with only its own photos) →
 * merge the chunk files → set the page boxes.
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
        $paths = [];
        $done = 0;

        foreach (self::chunks($plan->pageKeys, $this->pageBytes($calendar, $assets, $variant)) as $index => $pageKeys) {
            $paths[] = $this->renderChunk($calendar, $plan, $pageKeys, $directory, sprintf('chunk-%04d', $index), $assets);
            $done += count($pageKeys);
            $progress($done, $plan->pageCount());
        }

        $merged = $this->merger->merge($paths, $directory);

        try {
            PdfPageBoxes::apply(
                $merged,
                CalendarPdfPlan::pt($plan->sheetWidth),
                CalendarPdfPlan::pt($plan->sheetHeight),
                CalendarPdfPlan::pt($plan->trimInset),
                CalendarPdfPlan::pt($plan->bleedInset),
                ['title' => $calendar->name, 'creator' => 'WBoost', 'producer' => 'WBoost (Chromium, qpdf)', 'createdAt' => $this->clock->now()],
            );
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
