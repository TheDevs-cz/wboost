<?php

declare(strict_types=1);

namespace WBoost\Web\ConsoleCommands;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use WBoost\Web\Services\Palette\PaletteBackfillPlanner;
use WBoost\Web\Value\PaletteProjectPlan;

/**
 * Dry run of the project-palette backfill (F7, plan §2): runs the pure
 * {@see PaletteBackfillPlanner} over every project and prints what the P1a
 * migration WOULD create plus every case it would change. Read-only — it
 * reads the raw JSONB columns over DBAL (no hydration, so a colour Doctrine
 * would choke on is reported instead of crashing the run).
 */
#[AsCommand('app:palette:plan', 'Dry-run the project palette backfill and report what it would change (read-only)')]
final class PalettePlanConsoleCommand extends Command
{
    private const array FINDING_LABELS = [
        'split_duplicate' => 'Split duplicates (same hex, conflicting print data) — palette entries',
        'print_gap_filled' => 'Manual colours gaining print data from a merged palette colour (§7 fill the gap)',
        'hex_variant_merged' => 'Case / 3-digit hex variants merged',
        'custom_hex_literal_change' => 'Custom colours whose printed HEX / color(n) literal changes',
        'print_literal_variant' => 'Print data merged up to whitespace',
        'stale_detected' => 'Stale detected colours (persisted, no longer in the logo)',
        'non_hex_detected' => 'Non-hex detected logo strings (rgb() …)',
        'invalid_persisted_color' => 'Invalid persisted colours (hydration throws today)',
        'non_normalizable_hex' => 'Hex not #rgb/#rrggbb (computeColors skips it)',
        'in_manual_duplicate' => 'Duplicate hex within one manual',
        'cmyk_conflict' => 'CMYK conflicts (BrandCmykPalette first-wins)',
        'role_vote' => 'Palette colours whose manuals disagree on the role',
        'role_change' => 'Manuals whose primary/secondary pages change (D2)',
        'avatar_change' => 'Projects whose monogram colour changes',
        'parity_broken' => 'PARITY BROKEN (computeColors / BrandCmykPalette) — planner bug',
    ];

    public function __construct(
        readonly private Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('report', null, InputOption::VALUE_REQUIRED, 'Write every finding to this CSV file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<array{id: string, name: string}> $projects */
        $projects = $this->connection->fetchAllAssociative('SELECT id, name FROM project ORDER BY id');

        /** @var list<array{id: string, project_id: string, name: string, type: string, logo: string, detected_colors: string, custom_colors: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, project_id, name, type, logo, detected_colors, custom_colors FROM manual ORDER BY created_at, id',
        );

        $manualsByProject = [];
        foreach ($rows as $row) {
            $manualsByProject[$row['project_id']][] = PaletteBackfillPlanner::manualFromStorage(
                $row['id'],
                $row['name'],
                $row['type'] === 'brand',
                $row['logo'],
                $row['detected_colors'],
                $row['custom_colors'],
            );
        }

        $totals = ['projects' => 0, 'withManuals' => 0, 'withPalette' => 0, 'palette' => 0, 'logoLinks' => 0, 'customLinks' => 0];
        $counts = array_fill_keys(array_keys(self::FINDING_LABELS), 0);
        /** @var list<list<string>> $csv */
        $csv = [];
        /** @var list<array{string, PaletteProjectPlan}> $plans */
        $plans = [];

        foreach ($projects as $project) {
            $totals['projects']++;
            $manuals = $manualsByProject[$project['id']] ?? [];

            if ($manuals !== []) {
                $totals['withManuals']++;
            }

            $plan = PaletteBackfillPlanner::plan($manuals);
            $plans[] = [$project['name'], $plan];

            if ($plan->palette !== []) {
                $totals['withPalette']++;
            }

            $totals['palette'] += count($plan->palette);

            foreach ($plan->links as $link) {
                $link['logoHex'] !== null ? $totals['logoLinks']++ : $totals['customLinks']++;
            }

            foreach ($plan->findings as $finding) {
                $counts[$finding['type']] = ($counts[$finding['type']] ?? 0) + 1;
                $csv[] = [$finding['type'], $project['id'], $project['name'], $finding['manualId'], $finding['manual'], $finding['hex'], $finding['detail']];
            }
        }

        $io->title('Palette backfill — dry run (nothing is written)');

        $io->table(['Metric', 'Value'], [
            ['Projects', (string) $totals['projects']],
            ['Projects with manuals', (string) $totals['withManuals']],
            ['Projects with a palette after backfill', (string) $totals['withPalette']],
            ['Palette colours (project_color)', (string) $totals['palette']],
            ['Links (manual_palette_color)', sprintf('%d (%d logo, %d custom)', $totals['logoLinks'] + $totals['customLinks'], $totals['logoLinks'], $totals['customLinks'])],
        ]);

        $findingRows = [];
        foreach (self::FINDING_LABELS as $type => $label) {
            $findingRows[] = [$label, (string) $counts[$type]];
        }
        $io->table(['Finding', 'Count'], $findingRows);

        $io->section('Role changes per manual (D2 — role per project, majority, tie primary > secondary > none)');
        $lines = [];
        foreach ($plans as [$projectName, $plan]) {
            foreach ($plan->roleChanges as $change) {
                $lines[] = sprintf(
                    '%s → %s: primary %s → %s | secondary %s → %s',
                    $projectName,
                    $change['manual'],
                    self::hexList($change['primaryBefore']),
                    self::hexList($change['primaryAfter']),
                    self::hexList($change['secondaryBefore']),
                    self::hexList($change['secondaryAfter']),
                );
            }
        }
        $lines === [] ? $io->text('none') : $io->listing($lines);

        $reportPath = $input->getOption('report');
        if (is_string($reportPath) && $reportPath !== '') {
            $handle = fopen($reportPath, 'w');

            if ($handle === false) {
                $io->error(sprintf('Cannot write %s', $reportPath));

                return self::FAILURE;
            }

            fputcsv($handle, ['type', 'project_id', 'project', 'manual_id', 'manual', 'hex', 'detail'], escape: '');
            foreach ($csv as $line) {
                fputcsv($handle, $line, escape: '');
            }
            fclose($handle);

            $io->text(sprintf('Report: %d findings written to %s', count($csv), $reportPath));
        }

        if ($counts['parity_broken'] > 0) {
            $io->error('Parity broken for some projects — see the report.');

            return self::FAILURE;
        }

        $io->success('Dry run done — nothing was written.');

        return self::SUCCESS;
    }

    /**
     * @param list<string> $hexes
     */
    private static function hexList(array $hexes): string
    {
        return $hexes === [] ? '–' : implode(', ', $hexes);
    }
}
