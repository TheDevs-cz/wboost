<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Services\Security\ManualVoter;

/**
 * The manual's former Fonty tab (F8, 2026-11-05). Manual fonts are edited in
 * the "Písma v manuálech" box of the project Fonty page now; the route stays
 * so old links / bookmarks land on that manual's section there.
 */
final class ManualFontsController extends AbstractController
{
    #[Route(path: '/manual/{id}/fonts', name: 'manual_fonts')]
    #[IsGranted(ManualVoter::EDIT, 'manual')]
    public function __invoke(Manual $manual): Response
    {
        return $this->redirectToRoute('fonts_list', [
            'id' => $manual->project->id->toString(),
            '_fragment' => 'manual-fonts-' . $manual->id->toString(),
        ]);
    }
}
