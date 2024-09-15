<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use WBoost\Web\Services\SvgColorsMapper;
use WBoost\Web\Services\UploaderHelper;

final class TwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private UploaderHelper $uploaderHelper,
        readonly private SvgColorsMapper $svgColorsMapper,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('uploaded_asset', $this->uploaderHelper->getPublicPath(...)),
            new TwigFunction('remap_svg_colors', $this->svgColorsMapper->mapToDataUri(...)),
        ];
    }
}
