<?php

declare(strict_types=1);

namespace WBoost\Web\ConsoleCommands;

use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Message\Image\PurgeFileUpload;
use WBoost\Web\Repository\FileUploadRepository;

/**
 * Permanently removes gallery images whose trash-bin retention window
 * (FileUpload::TRASH_RETENTION_DAYS) has passed — the row AND the storage
 * object. Idempotent and safe to re-run; meant to run daily from cron.
 */
#[AsCommand('app:gallery:purge-trash', 'Permanently delete gallery images past the trash-bin retention window')]
final class PurgeGalleryTrashConsoleCommand extends Command
{
    public function __construct(
        readonly private FileUploadRepository $fileUploadRepository,
        readonly private MessageBusInterface $bus,
        readonly private ClockInterface $clock,
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

        foreach ($expired as $file) {
            $this->bus->dispatch(new PurgeFileUpload($file->id));
            $io->writeln(sprintf('Purged %s (trashed %s)', $file->path, $file->deletedAt?->format('Y-m-d H:i') ?? '?'));
        }

        $io->success(sprintf('Purged %d image(s) from the trash bin.', count($expired)));

        return Command::SUCCESS;
    }
}
