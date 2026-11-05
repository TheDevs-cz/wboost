<?php

declare(strict_types=1);

namespace WBoost\Web\ConsoleCommands;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\Project;
use WBoost\Web\Query\GetManuals;
use WBoost\Web\Query\GetProjectAvatars;
use WBoost\Web\Query\GetProjectCardFacts;
use WBoost\Web\Services\Print\BrandCmykPalette;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Value\LogoColorVariant;
use WBoost\Web\Value\LogoTypeVariant;
use WBoost\Web\Value\ManualColor;

/**
 * Writes every colour-derived output of every project to a stable JSON file,
 * so the palette migration (F7, `docs/redesign/palette-plan.md`) can be
 * checked by diffing a snapshot taken before (A) with one taken after (C).
 *
 * It goes ONLY through the public read model — `Manual`'s colour methods,
 * `GetManuals`, `ResolveRichTextOptions`, `BrandCmykPalette`,
 * `GetProjectAvatars`, `GetProjectCardFacts` — so it keeps working when the
 * storage behind them changes. Output is deterministic (projects by id,
 * manuals in `allForProject` order, no timestamps of the run). Read-only.
 */
#[AsCommand('app:palette:snapshot', 'Snapshot every colour-derived output (manuals, swatches, print CMYK, avatars) to a JSON file')]
final class PaletteSnapshotConsoleCommand extends Command
{
    public function __construct(
        readonly private EntityManagerInterface $entityManager,
        readonly private GetManuals $getManuals,
        readonly private ResolveRichTextOptions $resolveRichTextOptions,
        readonly private BrandCmykPalette $brandCmykPalette,
        readonly private GetProjectAvatars $getProjectAvatars,
        readonly private GetProjectCardFacts $getProjectCardFacts,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('out', InputArgument::REQUIRED, 'Path of the JSON file to write');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $out = $input->getArgument('out');
        assert(is_string($out));

        /** @var list<Project> $projects */
        $projects = $this->entityManager->getRepository(Project::class)->findBy([], ['id' => 'ASC']);

        $avatars = $this->getProjectAvatars->forProjects($projects);
        $facts = $this->getProjectCardFacts->forProjects($projects);

        $snapshot = ['snapshot' => 'app:palette:snapshot', 'version' => 1, 'projects' => []];
        $manualCount = 0;
        $errorCount = 0;

        foreach ($projects as $project) {
            $projectId = $project->id->toString();
            $manuals = $this->getManuals->allForProject($project->id);
            $avatar = $avatars[$projectId] ?? null;

            $manualsOut = [];
            foreach ($manuals as $manual) {
                $manualsOut[] = $this->manual($manual, $errorCount);
                $manualCount++;
            }

            $cmyk = self::attempt(function () use ($project): array {
                $palette = [];

                foreach ($this->brandCmykPalette->forProject($project->id) as $hex => $color) {
                    $palette[$hex] = [$color->c, $color->m, $color->y, $color->k];
                }

                ksort($palette);

                return $palette;
            }, $errorCount);

            $snapshot['projects'][] = [
                'id' => $projectId,
                'name' => $project->name,
                'hasPalette' => ($facts[$projectId] ?? null)?->hasPalette,
                'avatar' => $avatar === null ? null : [
                    'logoPath' => $avatar->logoPath,
                    'initials' => $avatar->initials,
                    'backgroundHex' => $avatar->backgroundHex,
                    'textHex' => $avatar->textHex,
                ],
                'computeColors' => self::attempt(fn (): array => $this->resolveRichTextOptions->forProject($project->id)->colors, $errorCount),
                'brandCmykPalette' => $cmyk,
                'overviewChips' => self::attempt(static fn (): array => self::overviewChips($manuals), $errorCount),
                'manuals' => $manualsOut,
            ];
        }

        $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        if (file_put_contents($out, $json . "\n") === false) {
            $io->error(sprintf('Cannot write %s', $out));

            return self::FAILURE;
        }

        $io->success(sprintf('%d projects, %d manuals written to %s (%d fields errored — see "error" keys).', count($projects), $manualCount, $out, $errorCount));

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function manual(Manual $manual, int &$errorCount): array
    {
        $logo = [];

        foreach (LogoTypeVariant::cases() as $type) {
            $variants = [];

            foreach (LogoColorVariant::cases() as $colorVariant) {
                $variants[$colorVariant->value] = self::attempt(static function () use ($manual, $type, $colorVariant): array {
                    $pairs = [];

                    // Pairs, not an object: the order is the replacement order,
                    // and PHP would turn numeric hex keys into integers.
                    foreach ($manual->logoColorMapping($type->value, $colorVariant->value) as $from => $to) {
                        $pairs[] = [(string) $from, $to];
                    }

                    return [
                        'background' => $manual->logoBackground($type->value, $colorVariant->value),
                        'mapping' => $pairs,
                    ];
                }, $errorCount);
            }

            $logo[$type->value] = [
                'hasImage' => $manual->logo->variant($type) !== null,
                'detectedColors' => $manual->logo->variant($type)?->detectedColors,
                'colorVariants' => $variants,
            ];
        }

        return [
            'id' => $manual->id->toString(),
            'name' => $manual->name,
            'type' => $manual->type->value,
            'createdAt' => $manual->createdAt->format(\DATE_ATOM),
            'colorsCount' => self::attempt(static fn (): int => $manual->colorsCount(), $errorCount),
            'detectedColors' => self::attempt(static fn (): array => self::colors($manual->detectedColors()), $errorCount),
            'customColors' => self::attempt(static fn (): array => self::colors(self::customColors($manual)), $errorCount),
            'primaryColors' => self::attempt(static fn (): array => self::colors($manual->primaryColors()), $errorCount),
            'secondaryColors' => self::attempt(static fn (): array => self::colors($manual->secondaryColors()), $errorCount),
            'colorN' => self::attempt(static function () use ($manual): array {
                $colors = [];

                for ($n = 1; $n <= $manual->colorsCount(); $n++) {
                    $colors[] = $manual->color($n)->hex;
                }

                return $colors;
            }, $errorCount),
            'logo' => $logo,
        ];
    }

    /**
     * The single place that reads the custom list (a method since P1b).
     *
     * @return array<ManualColor>
     */
    private static function customColors(Manual $manual): array
    {
        return $manual->customColors();
    }

    /**
     * The overview card chips (`project_dashboard.html.twig`): the first
     * manual with colours, detected ++ custom, first 12.
     *
     * @param array<Manual> $manuals
     * @return list<string>
     */
    private static function overviewChips(array $manuals): array
    {
        foreach ($manuals as $manual) {
            if ($manual->colorsCount() > 0) {
                $chips = array_map(
                    static fn (ManualColor $color): string => $color->color->hex,
                    array_merge($manual->detectedColors(), self::customColors($manual)),
                );

                return array_slice(array_values($chips), 0, 12);
            }
        }

        return [];
    }

    /**
     * @param array<ManualColor> $colors
     * @return list<array<string, mixed>>
     */
    private static function colors(array $colors): array
    {
        return array_values(array_map(static fn (ManualColor $color): array => [
            'hex' => $color->color->hex,
            'type' => $color->type?->value,
            'cmyk' => $color->cmyk,
            'pantone' => $color->pantone,
            'ral' => $color->ral,
        ], $colors));
    }

    /**
     * A field that throws today (e.g. a non-hex logo colour) is recorded as
     * an error instead of aborting the snapshot — "it threw" is part of the
     * behaviour a later snapshot must reproduce.
     *
     * @template T
     * @param callable(): T $compute
     * @return T|array{error: string}
     */
    private static function attempt(callable $compute, int &$errorCount): mixed
    {
        try {
            return $compute();
        } catch (\Throwable $e) {
            $errorCount++;

            return ['error' => $e::class . ': ' . $e->getMessage()];
        }
    }
}
