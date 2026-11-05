<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Palette;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\ProjectColor;
use WBoost\Web\Query\GetProjectPaletteUsage;
use WBoost\Web\Query\PaletteColorUsageRow;
use WBoost\Web\Repository\ProjectColorRepository;
use WBoost\Web\Services\Security\ProjectVoter;

/**
 * "Paleta barev" (F7 P2, `docs/redesign/palette-plan.md` §4): every palette
 * colour of the project — linked or not ("Nepoužitá") — in two groups, "Z
 * logotypu" (≥1 manual's logo carries it) and "Přidané barvy", palette order
 * inside each. Anyone who can view the project sees it (read-only, §692);
 * `project_edit` autosaves the cards through
 * {@see EditProjectColorController} and friends.
 */
final class ProjectPaletteController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'project_palette';

    public function __construct(
        readonly private ProjectColorRepository $projectColorRepository,
        readonly private GetProjectPaletteUsage $getProjectPaletteUsage,
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
        ]);
    }
}
