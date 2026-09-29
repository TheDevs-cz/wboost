<?php

declare(strict_types=1);

namespace WBoost\Web\Api\ExportVersions;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Query\GetExportVersions;
use WBoost\Web\Services\Template\ApiSubjectLoader;
use WBoost\Web\Services\Template\ExportVersionApiView;

/**
 * A fill surface's export history — `/template-groups/{groupId}/export-versions`
 * or `/template-variants/{variantId}/export-versions`, pinned first.
 *
 * @implements ProviderInterface<ExportVersionResponse>
 */
final readonly class ExportVersionsProvider implements ProviderInterface
{
    public function __construct(
        private ApiSubjectLoader $subjectLoader,
        private GetExportVersions $getExportVersions,
        private ExportVersionApiView $view,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     * @return list<ExportVersionResponse>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        if (array_key_exists('groupId', $uriVariables)) {
            $group = $this->subjectLoader->group($uriVariables['groupId']);
            $versions = $this->getExportVersions->forGroup($group->id)->all();
            $variants = $this->subjectLoader->members($group);
        } else {
            $variant = $this->subjectLoader->variant($uriVariables['variantId'] ?? null);
            $versions = $this->getExportVersions->forVariant($variant->id)->all();
            $variants = [$variant];
        }

        $summaries = $this->view->summaries($versions, $variants);

        return array_map(
            static fn (TemplateExportVersion $version): ExportVersionResponse => ExportVersionMapper::summary(
                $version,
                $summaries[$version->id->toString()] ?? ['texts' => [], 'pictures' => 0, 'hidden' => 0],
            ),
            $versions,
        );
    }
}
