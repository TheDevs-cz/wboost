<?php

declare(strict_types=1);

namespace WBoost\Web\ConsoleCommands;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Message\Manual\ImportManualImagesToGallery;
use WBoost\Web\Services\Manual\ManualGalleryImport;
use WBoost\Web\Value\ManualGallery\ManualGalleryImportItem;

/**
 * Content pages P3, one-off: every manual's pictures (content page image
 * elements incl. the intro, mockup page slots) become gallery pictures in
 * `Manuál / <manual>`, REFERENCING the existing storage keys — no copy, no
 * delete. Idempotent (keyed by storage key); `--dry-run` only prints the
 * plan. A key missing from storage is skipped and listed.
 */
#[AsCommand('app:manual:import-images-to-gallery', 'Register existing manual pictures (content + mockup pages) in the project gallery')]
final class ImportManualImagesToGalleryConsoleCommand extends Command
{
    public function __construct(
        readonly private EntityManagerInterface $entityManager,
        readonly private ManualGalleryImport $import,
        readonly private MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only print what would be done')
            ->addOption('manual', null, InputOption::VALUE_REQUIRED, 'Limit the run to one manual id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $manualOption = $input->getOption('manual');

        $qb = $this->entityManager->createQueryBuilder()
            ->select('m.id')
            ->from(Manual::class, 'm')
            ->orderBy('IDENTITY(m.project)')
            ->addOrderBy('m.createdAt')
            ->addOrderBy('m.id');

        if (is_string($manualOption) && $manualOption !== '') {
            if (!Uuid::isValid($manualOption)) {
                $io->error('--manual must be a UUID.');

                return Command::INVALID;
            }

            $qb->where('m.id = :id')->setParameter('id', $manualOption);
        }

        // Ids only: the unit of work is cleared after every manual, so each
        // one is loaded fresh (no detached entities, bounded memory).
        /** @var list<array{id: UuidInterface}> $rows */
        $rows = $qb->getQuery()->getResult();
        $manualIds = array_map(static fn (array $row): UuidInterface => $row['id'], $rows);

        $totals = [ManualGalleryImportItem::CREATE => 0, ManualGalleryImportItem::LINK => 0, ManualGalleryImportItem::DONE => 0, ManualGalleryImportItem::MISSING => 0];
        $leftover = 0;

        foreach ($manualIds as $manualId) {
            $this->entityManager->clear();
            $manual = $this->entityManager->find(Manual::class, $manualId);

            if ($manual === null) {
                continue;
            }

            $items = $this->import->plan($manual);

            if ($items === []) {
                continue;
            }

            $counts = array_count_values(array_map(static fn (ManualGalleryImportItem $item): string => $item->action, $items));
            foreach ($counts as $action => $count) {
                $totals[$action] += $count;
            }

            $io->section(sprintf('%s (%s, project %s)', $manual->name, $manual->id->toString(), $manual->project->name));
            $io->writeln(sprintf(
                '  create %d · link %d · already done %d · missing in storage %d',
                $counts[ManualGalleryImportItem::CREATE] ?? 0,
                $counts[ManualGalleryImportItem::LINK] ?? 0,
                $counts[ManualGalleryImportItem::DONE] ?? 0,
                $counts[ManualGalleryImportItem::MISSING] ?? 0,
            ));

            foreach ($items as $item) {
                if ($item->action === ManualGalleryImportItem::DONE) {
                    continue;
                }

                $io->writeln(sprintf(
                    '  %-7s %-7s %s [%s] %s',
                    $item->action,
                    $item->source,
                    $item->pageName,
                    $item->where,
                    $item->path,
                ));
            }

            if ($dryRun || !array_filter($items, static fn (ManualGalleryImportItem $item): bool => $item->isPending())) {
                continue;
            }

            $this->bus->dispatch(new ImportManualImagesToGallery($manual->id));
            $this->entityManager->clear();

            // Proof of idempotency: a fresh plan must have nothing left to do.
            $reloaded = $this->entityManager->find(Manual::class, $manualId);
            $after = $reloaded !== null ? $this->import->plan($reloaded) : [];
            $pending = count(array_filter($after, static fn (ManualGalleryImportItem $item): bool => $item->isPending()));
            $leftover += $pending;

            if ($pending > 0) {
                $io->warning(sprintf('%d picture(s) still pending after the import of %s.', $pending, $manual->name));
            }
        }

        $io->newLine();
        $io->writeln(sprintf(
            '%s%d manual(s): create %d · link %d · already done %d · missing in storage %d',
            $dryRun ? '[dry run] ' : '',
            count($manualIds),
            $totals[ManualGalleryImportItem::CREATE],
            $totals[ManualGalleryImportItem::LINK],
            $totals[ManualGalleryImportItem::DONE],
            $totals[ManualGalleryImportItem::MISSING],
        ));

        if ($totals[ManualGalleryImportItem::MISSING] > 0) {
            $io->warning('Pictures missing in storage were skipped (no gallery row) — they render broken today already.');
        }

        if ($leftover > 0) {
            $io->error(sprintf('%d picture(s) are still pending after the run.', $leftover));

            return Command::FAILURE;
        }

        $io->success($dryRun ? 'Dry run — nothing was written.' : 'Manual pictures are in the gallery.');

        return Command::SUCCESS;
    }
}
