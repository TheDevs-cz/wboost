<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * One PDF export of a calendar, as `manage_calendar` "export_pdf" /
 * "export_status" report it.
 */
readonly final class CalendarExportResponse
{
    public function __construct(
        /** Pass it to manage_calendar "export_status". */
        public string $exportId,
        /** "print" (bleed + crop marks as set, full-resolution photos) | "approval" (trimmed pages, small file). */
        public string $variant,
        /** "queued" | "running" | "done" | "failed". Poll "export_status" every few seconds while queued / running. */
        public string $status,
        public int $pagesDone,
        /** 0 until the job has started. */
        public int $pagesTotal,
        /** The calendar revision the file shows. */
        public int $revision,
        /** The name the file downloads under. */
        public string $fileName,
        /** Only when done. */
        public null|int $sizeBytes,
        /** Only when done: the PDF. Whoever has the link can download the file without an account — hand it to the user, do not publish it. */
        public null|string $downloadUrl,
        /** Only when failed: a Czech sentence for the user. Ask for the export again to retry. */
        public null|string $error,
    ) {
    }
}
