<?php

declare(strict_types=1);

namespace WBoost\Web\ConsoleCommands;

use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Exceptions\FileUploadInUse;
use WBoost\Web\Message\Image\PurgeFileUpload;
use WBoost\Web\Query\GetGalleryImageUsage;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Value\GalleryImageUsageSite;

/**
 * Permanently removes gallery images whose trash-bin retention window
 * (FileUpload::TRASH_RETENTION_DAYS) has passed — the row AND the storage
 * object. Idempotent and safe to re-run; meant to run daily from cron.
 *
 * A picture a template still references is NEVER purged automatically: it
 * stays in the bin (which says why) until the designer either takes it out
 * of the design or purges it by hand. Purging it would damage the design for
 * good — the editor would load a red stand-in, the export render nothing
 * there (the prod group whose every variant referenced a purged picture,
 * 2026-09-08).
 */
#[AsCommand('app:gallery:purge-trash', 'Permanently delete gallery images past the trash-bin retention window')]
final class PurgeGalleryTrashConsoleCommand extends Command
{
    public function __construct(
        readonly private FileUploadRepository $fileUploadRepository,
        readonly private MessageBusInterface $bus,
        readonly private ClockInterface $clock,
        readonly private GetGalleryImageUsage $galleryImageUsage,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $deadline = $this->clock->now()->modify(sprintf('-%d days', FileUpload::TRASH_RETENTION_DAYS));
        $expired = $this->fileUploadRepository->listTrashedBefore($deadline);

        if ($expired === []) {
            $io->success('Trash bin holds nothing past the retention window.');

            return Command::SUCCESS;
        }

        $usage = $this->usageByFile($expired);
        $purged = 0;
        $skipped = 0;

        foreach ($expired as $file) {
            $sites = $usage[$file->id->toString()] ?? [];
            if ($sites === []) {
                try {
                    $this->bus->dispatch(new PurgeFileUpload($file->id));
                } catch (HandlerFailedException $exception) {
                    // A reference that appeared between the scan and the
                    // purge — the handler is the safety net, report it the
                    // same way.
                    $previous = $exception->getPrevious();
                    if (!$previous instanceof FileUploadInUse) {
                        throw $exception;
                    }
                    $sites = $previous->sites;
                }
            }

            if ($sites !== []) {
                $skipped++;
                $io->writeln(sprintf(
                    'Skipped %s (trashed %s) — still used by template(s): %s',
                    $file->path,
                    $file->deletedAt?->format('Y-m-d H:i') ?? '?',
                    implode(', ', array_unique(array_map(static fn (GalleryImageUsageSite $site): string => $site->templateName, $sites))),
                ));

                continue;
            }

            $purged++;
            $io->writeln(sprintf('Purged %s (trashed %s)', $file->path, $file->deletedAt?->format('Y-m-d H:i') ?? '?'));
        }

        if ($skipped > 0) {
            $io->warning(sprintf('%d image(s) past the retention window stay in the bin: a template still uses them.', $skipped));
        }
        $io->success(sprintf('Purged %d image(s) from the trash bin.', $purged));

        return Command::SUCCESS;
    }

    /**
     * One usage scan per project (a gallery image is only ever referenced
     * from its own project), keyed by file id.
     *
     * @param list<FileUpload> $files
     * @return array<string, list<GalleryImageUsageSite>>
     */
    private function usageByFile(array $files): array
    {
        $byProject = [];
        foreach ($files as $file) {
            $byProject[$file->project->id->toString()][] = $file;
        }

        $usage = [];
        foreach ($byProject as $projectFiles) {
            $usage += $this->galleryImageUsage->forFiles($projectFiles[0]->project->id, $projectFiles);
        }

        return $usage;
    }
}
