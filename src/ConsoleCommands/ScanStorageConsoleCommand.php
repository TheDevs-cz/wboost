<?php

declare(strict_types=1);

namespace WBoost\Web\ConsoleCommands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use WBoost\Web\Services\FormatFileSize;
use WBoost\Web\Services\Storage\ScanStorage;

/**
 * Rebuilds the storage inventory shown at /admin/usage. Safe to re-run at any
 * time — it only reads from the bucket — so it is the one-time backfill and the
 * recurring refresh in a single command.
 */
#[AsCommand('app:storage:scan', 'Scan the S3/Minio bucket and rebuild the storage usage inventory')]
final class ScanStorageConsoleCommand extends Command
{
    public function __construct(
        readonly private ScanStorage $scanStorage,
        readonly private FormatFileSize $formatFileSize,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Scanning storage');

        $result = $this->scanStorage->scan();

        $io->table(['Metric', 'Value'], [
            ['Files', (string) $result->fileCount],
            ['Total size', ($this->formatFileSize)($result->totalSize)],
            ['Orphaned files', (string) $result->orphanCount],
            ['Orphaned size', ($this->formatFileSize)($result->orphanSize)],
            ['Dangling references', (string) count($result->danglingReferences)],
        ]);

        // A dangling reference is the mirror image of an orphan: the database
        // points at a key that is not in the bucket, i.e. a broken image
        // somewhere in the app. Worth surfacing loudly, but not an error.
        if ($result->danglingReferences !== []) {
            $io->warning('Some database rows reference files that are missing from storage:');
            $io->listing(array_slice($result->danglingReferences, 0, 20));

            if (count($result->danglingReferences) > 20) {
                $io->text(sprintf('… and %d more.', count($result->danglingReferences) - 20));
            }
        }

        $io->success(sprintf('Inventory rebuilt at %s.', $result->scannedAt->format('Y-m-d H:i:s')));

        return self::SUCCESS;
    }
}
