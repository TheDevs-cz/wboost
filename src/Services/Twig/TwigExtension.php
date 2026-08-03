<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use WBoost\Web\Repository\RegistrationRequestRepository;
use WBoost\Web\Services\FormatFileSize;
use WBoost\Web\Services\SvgColorsMapper;
use WBoost\Web\Services\UploaderHelper;

final class TwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private UploaderHelper $uploaderHelper,
        readonly private SvgColorsMapper $svgColorsMapper,
        readonly private RegistrationRequestRepository $registrationRequestRepository,
        readonly private FormatFileSize $formatFileSize,
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
            new TwigFunction('pending_registration_requests_count', $this->registrationRequestRepository->countPending(...)),
        ];
    }

    /**
     * @return array<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('file_size', $this->formatFileSize->__invoke(...)),
        ];
    }
}
