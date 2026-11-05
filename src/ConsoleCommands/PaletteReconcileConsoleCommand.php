<?php

declare(strict_types=1);

namespace WBoost\Web\ConsoleCommands;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use WBoost\Web\Entity\Project;
use WBoost\Web\Query\GetManuals;
use WBoost\Web\Services\Palette\ManualPaletteWriter;
use WBoost\Web\Services\Palette\ProjectManualColors;
use WBoost\Web\Services\Palette\VerifyPalette;
use WBoost\Web\Value\PaletteParityDiff;

/**
 * F7 release R2 (P1b) — run after every deploy that overlaps with R1 code:
 * a manual whose JSONB colours differ from the mirror of its links
 * ({@see VerifyPalette}: `jsonb_mirror_stale` or any other unexpected
 * difference) was written by R1 code during the blue-green overlap (or a
 * rollback), so its JSONB is the newer truth: the manual is RE-PROJECTED from
 * its JSONB ({@see ProjectManualColors}, the R1 rules — never sets roles,
 * never deletes palette colours), then the whole project's JSONB is
 * re-mirrored from the links ({@see ManualPaletteWriter::mirrorProject()}),
 * which also clears the owner-accepted differences R1 data still shows
 * (D2 roles, §7 gap fill / HEX spelling). A project-level difference
 * re-projects all of the project's manuals. One transaction per project;
 * safe to run on prod and to re-run (a second run finds nothing).
 */
#[AsCommand('app:palette:reconcile', 'Re-project manuals whose JSONB colours differ from their palette links, then re-mirror the JSONB')]
final class PaletteReconcileConsoleCommand extends Command
{
    public function __construct(
        readonly private EntityManagerInterface $entityManager,
        readonly private Connection $connection,
        readonly private GetManuals $getManuals,
        readonly private VerifyPalette $verifyPalette,
        readonly private ProjectManualColors $projectManualColors,
        readonly private ManualPaletteWriter $manualPaletteWriter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list what would be re-projected');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        /** @var list<Project> $projects */
        $projects = $this->entityManager->getRepository(Project::class)->findBy([], ['id' => 'ASC']);

        $reprojected = [];
        $remaining = 0;

        foreach ($projects as $project) {
            $manualIds = self::manualsToReproject($this->verifyPalette->project($project)['diffs'], $this->getManuals->allForProject($project->id));

            if ($manualIds === []) {
                continue;
            }

            foreach ($manualIds as $manualId => $name) {
                $reprojected[] = sprintf('%s → %s', $project->name, $name);
            }

            if ($dryRun) {
                continue;
            }

            $this->connection->transactional(function () use ($manualIds, $project): void {
                foreach (array_keys($manualIds) as $manualId) {
                    $this->projectManualColors->reproject(Uuid::fromString($manualId));
                }

                $this->manualPaletteWriter->mirrorProject($project->id->toString());
            });

            // Verify reads the manuals again — not the entities hydrated before the DBAL writes.
            $this->entityManager->clear();
            $project = $this->entityManager->find(Project::class, $project->id) ?? $project;

            foreach ($this->verifyPalette->project($project)['diffs'] as $diff) {
                if (!$diff->accepted()) {
                    $remaining++;
                    $io->warning(sprintf('Still different after re-projection: [%s] %s%s: %s', $diff->kind, $diff->project, $diff->manual === null ? '' : ' → ' . $diff->manual, $diff->detail));
                }
            }
        }

        $io->title($dryRun ? 'Palette reconcile — dry run' : 'Palette reconcile');
        $reprojected === [] ? $io->text('Every manual matches — nothing to do.') : $io->listing($reprojected);

        if ($remaining > 0) {
            $io->error(sprintf('%d differences remain — a projector / mirror bug, not stale data.', $remaining));

            return self::FAILURE;
        }

        $io->success(sprintf('%d manuals %s.', count($reprojected), $dryRun ? 'would be re-projected' : 're-projected'));

        return self::SUCCESS;
    }

    /**
     * @param list<PaletteParityDiff> $diffs
     * @param array<\WBoost\Web\Entity\Manual> $manuals
     * @return array<string, string> manual id → name
     */
    private static function manualsToReproject(array $diffs, array $manuals): array
    {
        $manualIds = [];

        foreach ($diffs as $diff) {
            if ($diff->accepted()) {
                continue;
            }

            if ($diff->manualId !== null) {
                $manualIds[$diff->manualId] = (string) $diff->manual;
                continue;
            }

            foreach ($manuals as $manual) {
                $manualIds[$manual->id->toString()] = $manual->name;
            }
        }

        return $manualIds;
    }
}
