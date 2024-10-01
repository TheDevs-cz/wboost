<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Services\SvgColorsMapper;

final class DownloadManualSvgController extends AbstractController
{
    public function __construct(
        readonly private SvgColorsMapper $svgColorsMapper,
    ) {
    }

    #[Route(path: '/stahnout-logo/{manualId}/svg/{logo}', name: 'download_manual_svg')]
    public function __invoke(
        #[MapEntity(id: 'manualId')]
        Manual $manual,
        string $logo,
        Request $request,
    ): Response {
        /** @var null|array<string, string> $colorsMapping */
        $colorsMapping = $request->get('colorsMapping');

        if (is_array($colorsMapping) === false) {
            $colorsMapping = [];
        }

        $image = match ($logo) {
            'horizontal' => $manual->logo->horizontal,
            'horizontalWithClaim' => $manual->logo->horizontalWithClaim,
            'vertical' => $manual->logo->vertical,
            'verticalWithClaim' => $manual->logo->verticalWithClaim,
            'symbol' => $manual->logo->symbol,
            default => throw $this->createNotFoundException('Unknown logo type'),
        };

        if ($image === null) {
            throw $this->createNotFoundException('Logo type not uploaded');
        }

        $svgContent = $this->svgColorsMapper->map($image->filePath, $colorsMapping);

        $downloadedFileName = $manual->project->slug . "-logo-" . $logo;

        return new Response($svgContent, headers: [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => 'attachment; filename="' . $downloadedFileName . '.svg"',
        ]);
    }
}
