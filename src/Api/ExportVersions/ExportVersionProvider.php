<?php

declare(strict_types=1);

namespace WBoost\Web\Api\ExportVersions;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Repository\TemplateExportVersionRepository;
use WBoost\Web\Services\Security\TemplateExportVersionVoter;
use WBoost\Web\Services\Template\ApiSubjectLoader;
use WBoost\Web\Services\Template\ExportVersionApiView;

/**
 * `GET /api/export-versions/{versionId}` — one version with its fill, seeded
 * against the current design. Also the loader + presenter the PATCH reuses.
 *
 * @implements ProviderInterface<ExportVersionDetailResponse>
 */
final readonly class ExportVersionProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private TemplateExportVersionRepository $versionRepository,
        private ApiSubjectLoader $subjectLoader,
        private ExportVersionApiView $view,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ExportVersionDetailResponse
    {
        return $this->present($this->load($uriVariables['versionId'] ?? null));
    }

    public function load(mixed $versionId): TemplateExportVersion
    {
        if (!is_string($versionId) || !Uuid::isValid($versionId)) {
            throw new BadRequestHttpException('Invalid export version id.');
        }

        $version = $this->versionRepository->find(Uuid::fromString($versionId));

        if ($version === null) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted(TemplateExportVersionVoter::MANAGE, $version)) {
            throw new AccessDeniedHttpException();
        }

        return $version;
    }

    public function present(TemplateExportVersion $version): ExportVersionDetailResponse
    {
        if ($version->group !== null) {
            $variants = $this->subjectLoader->members($version->group);
            $fill = $this->view->groupFill($version, $variants);
        } elseif ($version->variant !== null) {
            $variants = [$version->variant];
            $fill = $this->view->variantFill($version, $version->variant);
        } else {
            throw new NotFoundHttpException();
        }

        $summary = $this->view->summaries([$version], $variants)[$version->id->toString()]
            ?? ['texts' => [], 'pictures' => 0, 'hidden' => 0];

        return ExportVersionMapper::detail($version, $summary, $fill);
    }
}
