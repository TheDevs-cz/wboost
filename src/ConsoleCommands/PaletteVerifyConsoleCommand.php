<?php

declare(strict_types=1);

namespace WBoost\Web\ConsoleCommands;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use WBoost\Web\Entity\Project;
use WBoost\Web\Services\Palette\VerifyPalette;
use WBoost\Web\Value\PaletteParityDiff;

/**
 * F7: compares the colour read model R1 code builds from the manual JSONB
 * (since R2 a mirror of the links) with the one R2 serves from
 * `project_color` + `manual_palette_color`, per manual and per project.
 * Read-only, safe on prod. Exits 1 when a difference is not one of the
 * owner-accepted kinds ({@see PaletteParityDiff::ACCEPTED_KINDS}) — sync bugs
 * or a stale JSONB mirror (`jsonb_mirror_stale`: R1 code wrote it, or the R2
 * mirror never ran); `app:palette:reconcile` repairs the manuals concerned.
 */
#[AsCommand('app:palette:verify', 'Compare the JSONB colour read model with the palette tables (read-only)')]
final class PaletteVerifyConsoleCommand extends Command
{
    public function __construct(
        readonly private EntityManagerInterface $entityManager,
        readonly private VerifyPalette $verifyPalette,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('report', null, InputOption::VALUE_REQUIRED, 'Write every difference to this CSV file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<Project> $projects */
        $projects = $this->entityManager->getRepository(Project::class)->findBy([], ['id' => 'ASC']);

        $palette = 0;
        $logoLinks = 0;
        $customLinks = 0;
        /** @var list<PaletteParityDiff> $diffs */
        $diffs = [];

        foreach ($projects as $project) {
            $result = $this->verifyPalette->project($project);
            $palette += $result['palette'];
            $logoLinks += $result['logoLinks'];
            $customLinks += $result['customLinks'];
            array_push($diffs, ...$result['diffs']);
        }

        $io->title('Palette verify — JSONB mirror vs palette links');
        $io->table(['Metric', 'Value'], [
            ['Projects', (string) count($projects)],
            ['Palette colours (project_color)', (string) $palette],
            ['Links (manual_palette_color)', sprintf('%d (%d logo, %d custom)', $logoLinks + $customLinks, $logoLinks, $customLinks)],
        ]);

        $counts = [];
        foreach ($diffs as $diff) {
            $counts[$diff->kind] = ($counts[$diff->kind] ?? 0) + 1;
        }
        ksort($counts);

        $rows = [];
        foreach ($counts as $kind => $count) {
            $rows[] = [$kind, (string) $count, isset(PaletteParityDiff::ACCEPTED_KINDS[$kind]) ? 'accepted — ' . PaletteParityDiff::ACCEPTED_KINDS[$kind] : 'UNEXPECTED'];
        }
        $rows === [] ? $io->text('No differences.') : $io->table(['Difference', 'Count', 'Status'], $rows);

        $unexpected = array_values(array_filter($diffs, static fn (PaletteParityDiff $diff): bool => !$diff->accepted()));
        $accepted = array_values(array_filter($diffs, static fn (PaletteParityDiff $diff): bool => $diff->accepted()));

        if ($accepted !== []) {
            $io->section('Accepted differences (owner decisions)');
            $io->listing(array_map(self::line(...), $accepted));
        }

        $reportPath = $input->getOption('report');
        if (is_string($reportPath) && $reportPath !== '') {
            $handle = fopen($reportPath, 'w');

            if ($handle === false) {
                $io->error(sprintf('Cannot write %s', $reportPath));

                return self::FAILURE;
            }

            fputcsv($handle, ['kind', 'accepted', 'project_id', 'project', 'manual_id', 'manual', 'detail'], escape: '');
            foreach ($diffs as $diff) {
                fputcsv($handle, [$diff->kind, $diff->accepted() ? 'yes' : 'no', $diff->projectId, $diff->project, $diff->manualId ?? '', $diff->manual ?? '', $diff->detail], escape: '');
            }
            fclose($handle);

            $io->text(sprintf('Report: %d differences written to %s', count($diffs), $reportPath));
        }

        if ($unexpected !== []) {
            $io->section('UNEXPECTED differences');
            $io->listing(array_map(self::line(...), $unexpected));
            $io->error(sprintf('%d unexpected differences — run app:palette:reconcile.', count($unexpected)));

            return self::FAILURE;
        }

        $io->success(sprintf('Palette matches the JSONB read model (%d accepted differences).', count($accepted)));

        return self::SUCCESS;
    }

    private static function line(PaletteParityDiff $diff): string
    {
        return sprintf('[%s] %s%s: %s', $diff->kind, $diff->project, $diff->manual === null ? '' : ' → ' . $diff->manual, $diff->detail);
    }
}
