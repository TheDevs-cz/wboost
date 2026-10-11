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
use WBoost\Web\Services\Template\FillFieldOrder;
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
        readonly private FillFieldOrder $fillFieldOrder,
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
            // The page a logo variant gets when it is shown on its own.
            new TwigFunction('manual_logo_page', ManualPage::forLogoVariant(...)),
        ];
    }

    /**
     * @return array<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('file_size', $this->formatFileSize->__invoke(...)),
            // The fill panel's fields (hashes with an `inputId`) in the
            // template's admin-arranged order.
            new TwigFilter('arrange_fill_fields', $this->arrangeFillFields(...)),
        ];
    }

    /**
     * @param list<array{inputId: string}> $fields
     * @param list<string> $order
     *
     * @return list<array{inputId: string}>
     */
    private function arrangeFillFields(array $fields, array $order): array
    {
        return $this->fillFieldOrder->apply($fields, static fn (array $field): string => $field['inputId'], $order);
    }
}
