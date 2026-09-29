<?php

declare(strict_types=1);

namespace WBoost\Web\Api\TemplateGroups;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Api\Templates\ExportErrorResponse;
use WBoost\Web\Exceptions\ContainerOverflow;
use WBoost\Web\Exceptions\InvalidExportValue;
use WBoost\Web\Exceptions\InvalidRichTextValue;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Services\Template\ApiExportRenderer;
use WBoost\Web\Services\Template\ApiSubjectLoader;
use WBoost\Web\Value\RenderImageFormat;

/**
 * `POST /api/template-groups/{groupId}/preview/{variantId}` — one member
 * dimension of the group fill rendered for the screen (WebP), strict like the
 * export, recorded nowhere.
 *
 * @implements ProcessorInterface<GroupExportRequest, Response>
 */
final readonly class GroupPreviewProcessor implements ProcessorInterface
{
    public function __construct(
        private ApiSubjectLoader $subjectLoader,
        private ApiExportRenderer $apiExportRenderer,
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
        $variant = $this->subjectLoader->member($group, $uriVariables['variantId'] ?? null);

        try {
            $bytes = $this->apiExportRenderer->renderGroupMember($variant, $data->inputs, $data->images, $data->placements, RenderImageFormat::Webp);
        } catch (InvalidRichTextValue|InvalidExportValue|ContainerOverflow|TemplateRenderUnavailable $error) {
            return ExportErrorResponse::fromThrowable($error, $variant->id->toString()) ?? throw $error;
        }

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => RenderImageFormat::Webp->contentType(),
            'Cache-Control' => 'no-store',
        ]);
    }
}
