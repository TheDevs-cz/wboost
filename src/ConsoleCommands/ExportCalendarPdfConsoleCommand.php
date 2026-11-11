<?php

declare(strict_types=1);

namespace WBoost\Web\ConsoleCommands;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Message\Calendar\RequestCalendarExport;
use WBoost\Web\Repository\CalendarExportRepository;
use WBoost\Web\Services\Calendar\CalendarExports;
use WBoost\Web\Services\Calendar\CalendarPdfExporter;
use WBoost\Web\Services\FormatFileSize;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Value\Calendar\CalendarActor;
use WBoost\Web\Value\Calendar\CalendarExportVariant;

/**
 * Exports a calendar to PDF right here, without the queue — for support
 * ("the client cannot get the file") and for measuring an export: it reports
 * the time, the file size and PHP's peak memory. The export is an ordinary
 * `calendar_export` row (recorded in the history as the designer's), so the
 * web then offers the same file.
 */
#[AsCommand('app:calendar:export-pdf', 'Export a calendar to PDF synchronously (variant: print | approval)')]
final class ExportCalendarPdfConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private CalendarExportRepository $exportRepository,
        readonly private CalendarPdfExporter $exporter,
        readonly private CalendarExports $exports,
        readonly private ProvideIdentity $provideIdentity,
        readonly private FormatFileSize $formatFileSize,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('calendarId', InputArgument::REQUIRED, 'UUID of the calendar')
            ->addArgument('variant', InputArgument::OPTIONAL, 'print | approval', CalendarExportVariant::Print->value);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $calendarId = $input->getArgument('calendarId');
        $variant = CalendarExportVariant::tryFrom(is_string($input->getArgument('variant')) ? $input->getArgument('variant') : '');

        if (!is_string($calendarId) || !Uuid::isValid($calendarId) || $variant === null) {
            $io->error('Usage: app:calendar:export-pdf <calendar uuid> [print|approval]');

            return Command::INVALID;
        }

        $exportId = $this->provideIdentity->next();
        $started = microtime(true);

        // A fresh row every time (no reuse): the point is to run the export.
        $this->bus->dispatch(new RequestCalendarExport($exportId, Uuid::fromString($calendarId), $variant, CalendarActor::designer(), false));
        $this->exporter->run($exportId);

        $export = $this->exportRepository->get($exportId);

        if (!$export->isDownloadable()) {
            $io->error(sprintf('The export failed: %s (details are in the log).', $export->error ?? 'unknown'));

            return Command::FAILURE;
        }

        $io->table(['', ''], [
            ['Export', $export->id->toString()],
            ['Pages', (string) $export->progressTotal],
            ['File', $this->exports->storageUrl($export)],
            ['Size', ($this->formatFileSize)((int) $export->sizeBytes)],
            ['Time', sprintf('%.1f s', microtime(true) - $started)],
            ['Peak memory (PHP)', sprintf('%.0f MB', memory_get_peak_usage(true) / 1_000_000)],
        ]);

        return Command::SUCCESS;
    }
}
