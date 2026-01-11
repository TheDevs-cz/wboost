<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;
use WBoost\Web\Entity\WeeklyMenu;
use WBoost\Web\Value\WeeklyMenuMealType;

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
            'mealTypes' => WeeklyMenuMealType::cases(),
        ]);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A3', 'landscape');
        $dompdf->render();

        return $dompdf->output();
    }
}
