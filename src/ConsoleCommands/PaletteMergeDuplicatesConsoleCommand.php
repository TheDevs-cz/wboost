<?php

declare(strict_types=1);

namespace WBoost\Web\ConsoleCommands;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\ProjectColor;
use WBoost\Web\Message\Palette\MergeProjectColors;
use WBoost\Web\Query\GetProjectPaletteUsage;
use WBoost\Web\Repository\ProjectColorRepository;
use WBoost\Web\Services\Palette\PaletteColorMerge;

/**
 * "Sloučit" (F7 P4) from the CLI: every same-HEX group of a project's palette
 * merges into its DEFAULT survivor ({@see PaletteColorMerge::defaultSurvivor()},
 * the dialog's preselected radio) through the same {@see MergeProjectColors}
 * message the dialog posts. Dry run by default — it prints the dialog's
 * preview (resulting colour, manual changes, template print CMYK); `--apply`
 * merges. Take a pg_dump before applying on prod.
 */
#[AsCommand('app:palette:merge-duplicates', 'Merge same-HEX palette colours into their default survivor (dry run unless --apply)')]
final class PaletteMergeDuplicatesConsoleCommand extends Command
{
    public function __construct(
        readonly private Connection $connection,
        readonly private ProjectColorRepository $projectColorRepository,
        readonly private GetProjectPaletteUsage $getProjectPaletteUsage,
        readonly private MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'Only this project id');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Merge (otherwise only print the preview)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $only = $input->getOption('project');

        /** @var list<array{id: string, name: string}> $projects */
        $projects = $this->connection->fetchAllAssociative(<<<SQL
            SELECT DISTINCT project.id, project.name
            FROM project_color color
            JOIN project ON project.id = color.project_id
            GROUP BY project.id, project.name, lower(color.hex_key)
            HAVING count(*) > 1
            ORDER BY project.name
        SQL);

        $merged = 0;

        foreach ($projects as $project) {
            if (is_string($only) && $only !== '' && $only !== $project['id']) {
                continue;
            }

            $projectId = Uuid::fromString($project['id']);
            $colors = $this->projectColorRepository->forProject($projectId);
            $usage = $this->getProjectPaletteUsage->forProject($projectId);

            foreach (PaletteColorMerge::duplicateGroups($colors) as $hexKey => $members) {
                $survivor = PaletteColorMerge::defaultSurvivor($members, $usage);
                $preview = PaletteColorMerge::preview($survivor, $members, $usage);

                $io->section(sprintf('%s — #%s (%d colours)', $project['name'], strtoupper(ltrim($hexKey, '#')), count($members)));
                $io->writeln(sprintf(
                    'Result: CMYK %s · Pantone %s · RAL %s · Název %s',
                    PaletteColorMerge::format($preview->merged['cmyk']),
                    PaletteColorMerge::format($preview->merged['pantone']),
                    PaletteColorMerge::format($preview->merged['ral']),
                    PaletteColorMerge::format($preview->merged['name']),
                ));

                if ($preview->gained !== []) {
                    $io->writeln('Gains: ' . implode(', ', $preview->gained));
                }

                foreach ($preview->manualChanges as $change) {
                    $io->writeln(sprintf('  manual %s — %s', $change['manual'], implode('; ', $change['changes'])));
                }

                if ($preview->manualChanges === []) {
                    $io->writeln('  no manual changes');
                }

                if ($preview->changesTemplatePrint()) {
                    $io->writeln(sprintf('  template print CMYK %s → %s', $preview->printCmykBefore ?? 'ICC', $preview->printCmykAfter ?? 'ICC'));
                }

                if ($apply) {
                    $this->bus->dispatch(new MergeProjectColors(
                        $survivor->id,
                        array_map(static fn (ProjectColor $color): UuidInterface => $color->id, $preview->losers),
                    ));
                    $io->writeln('<info>merged</info>');
                }

                $merged++;
            }
        }

        $io->success(sprintf('%d same-HEX group(s) %s.', $merged, $apply ? 'merged' : 'found (dry run — pass --apply to merge)'));

        return Command::SUCCESS;
    }
}
