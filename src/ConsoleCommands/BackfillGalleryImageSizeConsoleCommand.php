<?php

declare(strict_types=1);

namespace WBoost\Web\ConsoleCommands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Services\Image\FileUploadPixelSizeBackfill;

/**
 * One-shot sweep recording the pixel size on every gallery image uploaded
 * before it was stored at upload time (2026-09). The gallery also heals rows
 * lazily as folders are opened, so this only front-loads the work; idempotent
 * and safe to re-run — a row that already has a size is never read again. Only
 * READS from storage.
 */
#[AsCommand('app:gallery:backfill-image-size', 'Record the pixel size of gallery images that were uploaded before it was stored')]
final class BackfillGalleryImageSizeConsoleCommand extends Command
{
    public function __construct(
        readonly private FileUploadRepository $fileUploadRepository,
        readonly private FileUploadPixelSizeBackfill $backfill,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $missing = $this->fileUploadRepository->listMissingPixelSize();

        if ($missing === []) {
            $io->success('Every gallery image already has a recorded size.');

            return Command::SUCCESS;
        }

        $filled = $this->backfill->backfill($missing);
        $unreadable = count($missing) - $filled;

        foreach ($missing as $file) {
            if (!$file->hasPixelSize()) {
                $io->writeln(sprintf('Unreadable: %s', $file->path));
            }
        }

        $io->success(sprintf(
            'Recorded the size of %d image(s); %d could not be read (object missing or undecodable).',
            $filled,
            $unreadable,
        ));

        return $unreadable > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
