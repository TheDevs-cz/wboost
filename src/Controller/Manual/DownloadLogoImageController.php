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
use WBoost\Web\Value\ImageFormat;

final class DownloadLogoImageController extends AbstractController
{
    public function __construct(
        readonly private SvgColorsMapper $svgColorsMapper,
    ) {
    }

    #[Route(path: '/stahnout-logo/{manualId}/{logo}.{format}', name: 'download_logo_image')]
    public function __invoke(
        #[MapEntity(id: 'manualId')]
        Manual $manual,
        string $logo,
        ImageFormat $format,
        Request $request,
    ): Response {
        /** @var null|array<string, string> $colorsMapping */
        $colorsMapping = $request->get('colorsMapping');

        if (is_array($colorsMapping) === false) {
            $colorsMapping = [];
        }

        /** @var null|string $backgroundQuery */
        $backgroundQuery = $request->get('background');
        $backgroundColor = '#' . ($backgroundQuery ?? 'ffffff');

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

        $imageContent = $this->svgColorsMapper->map($image->filePath, $colorsMapping);

        if ($format === ImageFormat::PNG) {
            $imagick = new \Imagick();
            $imagick->setResolution(300, 300);
            $imagick->setBackgroundColor(new \ImagickPixel('transparent'));
            $imagick->readImageBlob($imageContent);
            $imagick->setImageFormat('png32');
            $imageContent = $imagick->getImageBlob();
        }

        if ($format === ImageFormat::JPG) {
            $imagick = new \Imagick();
            $imagick->setResolution(300, 300);
            $imagick->setBackgroundColor(new \ImagickPixel($backgroundColor));
            $imagick->readImageBlob($imageContent);
            $imagick = $imagick->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality(100);
            $imageContent = $imagick->getImageBlob();
        }

        $downloadedFileName = $manual->project->slug . "-logo-" . $logo . '.' . $format->value;

        return new Response($imageContent, headers: [
            'Content-Type' => $format->contentType(),
            'Content-Disposition' => 'attachment; filename="' . $downloadedFileName . '"',
        ]);
    }
}
