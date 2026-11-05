<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Palette;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\ManualPaletteColor;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\ProjectColor;
use WBoost\Web\Query\GetManuals;
use WBoost\Web\Query\GetPaletteTemplateUsage;
use WBoost\Web\Query\GetProjectPaletteUsage;
use WBoost\Web\Query\PaletteColorUsageRow;
use WBoost\Web\Repository\ProjectColorRepository;
use WBoost\Web\Services\Palette\PaletteColorMerge;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Value\PaletteMergePreview;
use WBoost\Web\Value\PaletteTemplateUsage;
use WBoost\Web\Value\ProjectModule;

/**
 * "Paleta barev" (F7 P2, `docs/redesign/palette-plan.md` §4): every palette
 * colour of the project — linked or not ("Nepoužitá") — in two groups, "Z
 * logotypu" (≥1 manual's logo carries it) and "Přidané barvy", palette order
 * inside each. Anyone who can view the project sees it (read-only, §692);
 * `project_edit` autosaves the cards through
 * {@see EditProjectColorController} and friends.
 *
 * Below them the "Barvy v manuálech" box (F7 P3, D6 — it replaced the
 * manual's "Barevná paleta" tab): per manual its colours in manual order
 * (logo colours first — what `color(n)` and the default logo recolouring
 * index), edited with `manual_edit` through
 * {@see AddManualPaletteColorsController}, {@see RemoveManualPaletteColorController}
 * and {@see SortManualPaletteColorsController}.
 *
 * F7 P4: each card also counts the templates using its HEX
 * ({@see GetPaletteTemplateUsage} — one query, only with the Šablony module
 * on), and same-HEX colours get the "Stejný HEX" flag + a "Sloučit" dialog
 * for `project_edit` ({@see MergeProjectColorsController}), with one
 * preview per possible survivor ({@see PaletteColorMerge::preview()}).
 */
final class ProjectPaletteController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'project_palette';

    public function __construct(
        readonly private ProjectColorRepository $projectColorRepository,
        readonly private GetProjectPaletteUsage $getProjectPaletteUsage,
        readonly private GetManuals $getManuals,
        readonly private GetPaletteTemplateUsage $getPaletteTemplateUsage,
    ) {
    }

    #[Route(path: '/project/{id}/paleta', name: 'project_palette', methods: ['GET'])]
    #[IsGranted(ProjectVoter::VIEW, 'project')]
    public function __invoke(Project $project, Request $request): Response
    {
        $colors = $this->projectColorRepository->forProject($project->id);
        $usage = $this->getProjectPaletteUsage->forProject($project->id);

        $fromLogo = static fn (ProjectColor $color): bool => array_filter(
            $usage[$color->id->toString()] ?? [],
            static fn (PaletteColorUsageRow $row): bool => $row->fromLogo,
        ) !== [];

        return $this->render('project_palette.html.twig', [
            'project' => $project,
            'colors' => $colors,
            'logo_colors' => array_values(array_filter($colors, $fromLogo)),
            'added_colors' => array_values(array_filter($colors, static fn (ProjectColor $color): bool => !$fromLogo($color))),
            'usage' => $usage,
            // The colour "Přidat barvu" just created: flashed + HEX focused.
            'new_color_id' => $request->query->getString('nova'),
            'manual_colors' => $this->manualColors($project, $colors),
            'template_usage' => $project->hasModule(ProjectModule::Templates)
                ? $this->getPaletteTemplateUsage->forProject($project->id)
                : new PaletteTemplateUsage(),
            'duplicates' => $this->isGranted(ProjectVoter::EDIT, $project) ? self::duplicates($colors, $usage) : [],
        ]);
    }

    /**
     * Same-HEX groups for the "Sloučit" dialogs, keyed by the `hex_key`
     * without `#` (the dialog id): members in palette order, the default
     * survivor and a preview per possible survivor.
     *
     * @param array<ProjectColor> $colors
     * @param array<string, list<PaletteColorUsageRow>> $usage
     * @return array<string, array{members: list<ProjectColor>, survivor: ProjectColor, previews: array<string, PaletteMergePreview>}>
     */
    private static function duplicates(array $colors, array $usage): array
    {
        $groups = [];

        foreach (PaletteColorMerge::duplicateGroups($colors) as $hexKey => $members) {
            $previews = [];
            foreach ($members as $member) {
                $previews[$member->id->toString()] = PaletteColorMerge::preview($member, $members, $usage);
            }

            $groups[ltrim($hexKey, '#')] = [
                'members' => $members,
                'survivor' => PaletteColorMerge::defaultSurvivor($members, $usage),
                'previews' => $previews,
            ];
        }

        return $groups;
    }

    /**
     * Fragment of one manual's section in "Barvy v manuálech" — where the
     * box's writes and the old `/manual/{id}/colors` URL land.
     */
    public static function manualAnchor(Manual $manual): string
    {
        return 'manual-colors-' . $manual->id->toString();
    }

    /**
     * "Barvy v manuálech": every manual of the project (logo and brand —
     * both have colours), `created_at, id` order; per manual its logo
     * colours and added colours in manual order and the palette colours it
     * does not use yet ("Přidat z palety", palette order). Empty while the
     * Manuály module is switched off.
     *
     * @param array<ProjectColor> $colors the palette, palette order
     * @return list<array{manual: Manual, logo: list<ManualPaletteColor>, added: list<ManualPaletteColor>, available: list<ProjectColor>}>
     */
    private function manualColors(Project $project, array $colors): array
    {
        if (!$project->hasModule(ProjectModule::Manuals)) {
            return [];
        }

        $entries = [];

        foreach ($this->getManuals->allForProject($project->id) as $manual) {
            $links = $manual->paletteLinks();
            $used = [];

            foreach ($links as $link) {
                $used[$link->projectColor->id->toString()] = true;
            }

            $entries[] = [
                'manual' => $manual,
                'logo' => array_values(array_filter($links, static fn (ManualPaletteColor $link): bool => $link->isFromLogo())),
                'added' => array_values(array_filter($links, static fn (ManualPaletteColor $link): bool => !$link->isFromLogo())),
                'available' => array_values(array_filter($colors, static fn (ProjectColor $color): bool => !isset($used[$color->id->toString()]))),
            ];
        }

        return $entries;
    }
}
