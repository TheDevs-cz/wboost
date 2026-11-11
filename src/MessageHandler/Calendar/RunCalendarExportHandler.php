<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Calendar;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\Calendar\RunCalendarExport;
use WBoost\Web\Services\Calendar\CalendarPdfExporter;

/**
 * On the `job_bus`: no transaction wraps it, the exporter commits its own
 * progress. It never throws — a failed export is a `failed` row with a
 * message for the user, not a Messenger retry (the user has a retry button).
 */
#[AsMessageHandler(bus: 'job_bus')]
readonly final class RunCalendarExportHandler
{
    public function __construct(
        private CalendarPdfExporter $exporter,
    ) {
    }

    public function __invoke(RunCalendarExport $message): void
    {
        $this->exporter->run($message->exportId);
    }
}
