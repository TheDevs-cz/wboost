<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Services\Security\ManualVoter;

final class ManualFontsController extends AbstractController
{
    public function __construct(
        readonly private GetFonts $getFonts,
    ) {
    }

    #[Route(path: '/manual/{id}/fonts', name: 'manual_fonts')]
    #[IsGranted(ManualVoter::EDIT, 'manual')]
    public function __invoke(
        Request $request,
        Manual $manual,
    ): Response {
        return $this->render('manual_fonts.html.twig', [
            'project' => $manual->project,
            'manual' => $manual,
            'available_fonts' => $this->getFonts->allForProject($manual->project->id),
        ]);
    }
}
