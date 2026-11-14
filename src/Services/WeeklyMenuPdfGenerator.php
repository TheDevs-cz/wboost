<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

use Sensiolabs\GotenbergBundle\Enumeration\PaperSize;
use Sensiolabs\GotenbergBundle\Enumeration\Unit;
use Sensiolabs\GotenbergBundle\GotenbergPdfInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Entity\WeeklyMenu;

readonly class WeeklyMenuPdfGenerator
{
    public function __construct(
        private GotenbergPdfInterface $gotenberg,
        #[Autowire('%kernel.project_dir%/resources/fonts/roboto')]
        private string $robotoDirectory,
    ) {
    }

    public function generate(WeeklyMenu $menu, string $fileName): Response
    {
        $response = $this->gotenberg->html()
            ->content('pdf/weekly_menu.html.twig', [
                'menu' => $menu,
                'font_faces' => $this->fontFaces(),
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

    /**
     * Roboto as `data:` URLs — the page links nothing: the headless renderer
     * has no network access (docs/architecture/templates-render.md, "Renderer
     * isolation"), and a linked Google Fonts stylesheet would silently fall
     * back to another face. The files and ranges are the ones Google served
     * for `Roboto:wght@400;700` (resources/fonts/roboto/README.md); the font
     * is variable, so one face per subset covers regular and bold.
     *
     * @return list<array{src: string, unicodeRange: string}>
     */
    private function fontFaces(): array
    {
        $faces = [];

        /** @var list<array{file: string, unicodeRange: string}> $subsets */
        $subsets = json_decode((string) file_get_contents($this->robotoDirectory . '/faces.json'), true, flags: JSON_THROW_ON_ERROR);

        foreach ($subsets as $subset) {
            $faces[] = [
                'src' => 'data:font/woff2;base64,' . base64_encode((string) file_get_contents($this->robotoDirectory . '/' . $subset['file'])),
                'unicodeRange' => $subset['unicodeRange'],
            ];
        }

        return $faces;
    }
}
