<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Controller\Palette\ProjectPaletteController;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Services\Security\ManualVoter;

/**
 * The manual's former "Barevná paleta" tab (F7 P3 / F8, 2026-11-05). A
 * manual's colours are picked and ordered in the "Barvy v manuálech" box of
 * the project's Paleta barev page now; the route stays so old links and
 * bookmarks land on that manual's section there.
 *
 * 302, not 301: the target depends on the manual's project and on who asks
 * (VIEW members land on the read-only box), and browsers cache a 301
 * indefinitely — a later change of the target could not reach them. Same as
 * the Fonty tab's redirect (`ManualFontsController`).
 */
final class ManualColorsController extends AbstractController
{
    #[Route(path: '/manual/{id}/colors', name: 'manual_colors')]
    #[IsGranted(ManualVoter::VIEW, 'manual')]
    public function __invoke(Manual $manual): Response
    {
        return $this->redirectToRoute('project_palette', [
            'id' => $manual->project->id->toString(),
            '_fragment' => ProjectPaletteController::manualAnchor($manual),
        ]);
    }
}
