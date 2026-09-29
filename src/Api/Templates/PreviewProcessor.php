<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Templates;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Exceptions\ContainerOverflow;
use WBoost\Web\Exceptions\InvalidExportValue;
use WBoost\Web\Exceptions\InvalidRichTextValue;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Services\Template\ApiExportRenderer;
use WBoost\Web\Services\Template\ApiSubjectLoader;
use WBoost\Web\Value\RenderImageFormat;

/**
 * `POST /api/template-variants/{id}/preview` — the SAME strict render as the
 * export (same body, same 400s), but for the screen: WebP (a fraction of the
 * PNG's bytes) and NOT an export — no usage row, no history version. A
 * consumer's debounced live preview belongs here; only the user's actual
 * download goes to `/export`.
 *
 * @implements ProcessorInterface<ExportRequest, Response>
 */
final readonly class PreviewProcessor implements ProcessorInterface
{
    public function __construct(
        private ApiSubjectLoader $subjectLoader,
        private ApiExportRenderer $apiExportRenderer,
    ) {
    }

    /**
     * @param ExportRequest $data
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $variant = $this->subjectLoader->variant($uriVariables['id'] ?? null);

        try {
            $bytes = $this->apiExportRenderer->render($variant, $data->inputs, $data->images, RenderImageFormat::Webp);
        } catch (InvalidRichTextValue|InvalidExportValue|ContainerOverflow|TemplateRenderUnavailable $error) {
            return ExportErrorResponse::fromThrowable($error) ?? throw $error;
        }

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => RenderImageFormat::Webp->contentType(),
            'Cache-Control' => 'no-store',
        ]);
    }
}
