<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use WBoost\Web\Entity\Calendar;
use WBoost\Web\Exceptions\CalendarModelRefused;
use WBoost\Web\Exceptions\CalendarModelUnavailable;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Value\Calendar\CalendarExportVariant;

/**
 * Calendar → one PDF file on the local disk. The seam between the export job
 * ({@see CalendarPdfExporter}: row, storage, pruning) and the machinery that
 * draws ({@see CalendarPdfProducer}: node, Gotenberg) — tests replace it.
 */
interface CalendarPdfProducerInterface
{
    /**
     * @param string $directory an existing, empty work directory; the result lies in it
     * @param callable(int, int): void $progress called with (pages done, pages total): first (0, total), then after every chunk
     *
     * @return string absolute path of the PDF
     *
     * @throws CalendarModelRefused `no_layout`
     * @throws CalendarModelUnavailable
     * @throws TemplateRenderUnavailable
     */
    public function produce(Calendar $calendar, CalendarExportVariant $variant, string $directory, callable $progress): string;
}
