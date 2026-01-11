<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\WeeklyMenu;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\WeeklyMenu;
use WBoost\Web\Services\Security\WeeklyMenuVoter;
use WBoost\Web\Services\WeeklyMenuPdfGenerator;

final class DownloadWeeklyMenuPdfController extends AbstractController
{
    public function __construct(
        readonly private WeeklyMenuPdfGenerator $pdfGenerator,
    ) {
    }

    #[Route(path: '/weekly-menu/{menuId}/download-pdf', name: 'download_weekly_menu_pdf')]
    #[IsGranted(WeeklyMenuVoter::VIEW, 'menu')]
    public function __invoke(
        #[MapEntity(id: 'menuId')]
        WeeklyMenu $menu,
    ): Response {
        $pdfContent = $this->pdfGenerator->generate($menu);

        $filename = sprintf(
            'jidelnicek-%s-%s.pdf',
            $menu->project->slug,
            $menu->validFrom->format('Y-m-d'),
        );

        return new Response($pdfContent, headers: [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
