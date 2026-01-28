<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;
use WBoost\Web\Entity\WeeklyMenu;

readonly class WeeklyMenuPdfGenerator
{
    public function __construct(
        private Environment $twig,
    ) {
    }

    public function generate(WeeklyMenu $menu): string
    {
        $html = $this->twig->render('pdf/weekly_menu.html.twig', [
            'menu' => $menu,
        ]);

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A2', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
