<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Font;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\ManualFont;
use WBoost\Web\Entity\Project;
use WBoost\Web\Query\GetFontUsage;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Query\GetManualFonts;
use WBoost\Web\Query\GetManuals;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Value\ProjectModule;

final class FontsController extends AbstractController
{
    public function __construct(
        readonly private GetFonts $getFonts,
        readonly private GetFontUsage $getFontUsage,
        readonly private GetManuals $getManuals,
        readonly private GetManualFonts $getManualFonts,
    ) {
    }

    #[Route(path: '/project/{id}/fonts', name: 'fonts_list')]
    #[IsGranted(ProjectVoter::VIEW, 'project')]
    public function __invoke(Project $project): Response
    {
        $fonts = $this->getFonts->allForProject($project->id);

        return $this->render('fonts.html.twig', [
            'project' => $project,
            'fonts' => $fonts,
            'faces_count' => array_sum(array_map(static fn ($font): int => count($font->faces), $fonts)),
            // Where each face is used + references no project face satisfies.
            'usage' => $this->getFontUsage->forProject($project->id),
            'manual_fonts' => $this->manualFonts($project),
        ]);
    }

    /**
     * "Písma v manuálech" (F8, 2026-11-05 — replaces the manual's Fonty tab):
     * every BRAND manual with its fonts in manual order. Logo manuals have no
     * fonts. Empty while the Manuály module is switched off.
     *
     * @return list<array{manual: Manual, fonts: array<ManualFont>}>
     */
    private function manualFonts(Project $project): array
    {
        if (!$project->hasModule(ProjectModule::Manuals)) {
            return [];
        }

        $manuals = array_values(array_filter(
            $this->getManuals->allForProject($project->id),
            static fn (Manual $manual): bool => $manual->isBrandManual(),
        ));
        usort($manuals, static fn (Manual $a, Manual $b): int => $a->createdAt <=> $b->createdAt);

        return array_map(fn (Manual $manual): array => [
            'manual' => $manual,
            'fonts' => $this->getManualFonts->allForManual($manual->id),
        ], $manuals);
    }
}
