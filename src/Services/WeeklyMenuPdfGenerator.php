<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

use Sensiolabs\GotenbergBundle\Enumeration\PaperSize;
use Sensiolabs\GotenbergBundle\Enumeration\Unit;
use Sensiolabs\GotenbergBundle\GotenbergPdfInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Entity\WeeklyMenu;

readonly class WeeklyMenuPdfGenerator
{
    public function __construct(
        private GotenbergPdfInterface $gotenberg,
    ) {
    }

    public function generate(WeeklyMenu $menu, string $fileName): Response
    {
        $response = $this->gotenberg->html()
            ->content('pdf/weekly_menu.html.twig', [
                'menu' => $menu,
            ])
            ->paperStandardSize(PaperSize::A2)
            ->margins(8, 8, 8, 8, Unit::Millimeters)
            ->printBackground()
            ->waitForExpression("window.fontsReady === true")
            ->fileName($fileName)
            ->generate()
            ->stream();

        $disposition = HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $fileName . '.pdf');
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }
}
