<?php

declare(strict_types=1);

namespace WBoost\Web\Api\TemplateGroups;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use WBoost\Web\Api\Templates\ExportErrorResponse;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\ContainerOverflow;
use WBoost\Web\Exceptions\InvalidExportValue;
use WBoost\Web\Exceptions\InvalidRichTextValue;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Services\Template\ApiExportRenderer;
use WBoost\Web\Services\Template\ApiSubjectLoader;
use WBoost\Web\Services\Template\RecordExportVersion;
use WBoost\Web\Services\TemplateGroup\GroupExportArchive;
use WBoost\Web\Services\Usage\RecordExportUsage;
use WBoost\Web\Value\ExportChannel;
use WBoost\Web\Value\ExportFillValues;

/**
 * The group EXPORT — both operations of the web fill page's download buttons:
 *
 * - `POST /api/template-groups/{groupId}/export` → every member dimension as
 *   one ZIP (`{group}.zip` with `{group}-{dimension}.png` entries);
 * - `POST /api/template-groups/{groupId}/export/{variantId}` → one dimension
 *   as `{group}-{dimension}.png`.
 *
 * Renders are strict: the first dimension that fails answers its structured
 * 400 (with `variantId`) and nothing is recorded. On success every rendered
 * dimension counts in usage and the fill is snapshotted as ONE group version
 * — the ZIP and the single-dimension downloads of the same fill deduplicate,
 * with each other and with the web group page's exports.
 *
 * @implements ProcessorInterface<GroupExportRequest, Response>
 */
final readonly class GroupExportProcessor implements ProcessorInterface
{
    public function __construct(
        private ApiSubjectLoader $subjectLoader,
        private ApiExportRenderer $apiExportRenderer,
        private GroupExportArchive $archive,
        private RecordExportUsage $recordExportUsage,
        private RecordExportVersion $recordExportVersion,
    ) {
    }

    /**
     * @param GroupExportRequest $data
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $group = $this->subjectLoader->group($uriVariables['groupId'] ?? null);
        $single = array_key_exists('variantId', $uriVariables);

        $variants = $single
            ? [$this->subjectLoader->member($group, $uriVariables['variantId'])]
            : $this->subjectLoader->members($group);

        if ($variants === []) {
            throw new NotFoundHttpException('The group has no variants to export.');
        }

        /** @var list<array{TemplateVariant, string}> $renders */
        $renders = [];

        foreach ($variants as $variant) {
            try {
                $renders[] = [$variant, $this->apiExportRenderer->renderGroupMember($variant, $data->inputs, $data->images, $data->placements, shapeColors: $data->shapeColors)];
            } catch (InvalidRichTextValue|InvalidExportValue|ContainerOverflow|TemplateRenderUnavailable $error) {
                return ExportErrorResponse::fromThrowable($error, $variant->id->toString()) ?? throw $error;
            }
        }

        foreach ($variants as $variant) {
            $this->recordExportUsage->record($variant, ExportChannel::Api);
        }

        $this->recordExportVersion->recordGroup(
            $group,
            ExportChannel::Api,
            ExportFillValues::fromGroupApiRequest($data->inputs, $data->images, $data->placements, $data->shapeColors),
        );

        if ($single) {
            [$variant, $bytes] = $renders[0];

            return new Response($bytes, Response::HTTP_OK, [
                'Content-Type' => 'image/png',
                'Content-Disposition' => sprintf('attachment; filename="%s"', $this->archive->fileName($group, $variant)),
                'Cache-Control' => 'no-store',
            ]);
        }

        return new Response($this->archive->build($group, $renders), Response::HTTP_OK, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $this->archive->archiveName($group)),
            'Cache-Control' => 'no-store',
        ]);
    }
}
