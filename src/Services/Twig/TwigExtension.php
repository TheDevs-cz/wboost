<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use WBoost\Web\Query\GetProjectAvatars;
use WBoost\Web\Repository\RegistrationRequestRepository;
use WBoost\Web\Services\FormatFileSize;
use WBoost\Web\Services\SvgColorsMapper;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\ManualPage;

final class TwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private UploaderHelper $uploaderHelper,
        readonly private SvgColorsMapper $svgColorsMapper,
        readonly private RegistrationRequestRepository $registrationRequestRepository,
        readonly private FormatFileSize $formatFileSize,
        readonly private GetProjectAvatars $getProjectAvatars,
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
            new TwigFunction('project_avatar', $this->getProjectAvatars->forProject(...)),
            // The manual pages address their own texts by key; the enum carries
            // the defaults, so the template needs the case, not the string.
            new TwigFunction('manual_page', ManualPage::from(...)),
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
