<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Templates;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use WBoost\Web\Exceptions\ContainerOverflow;
use WBoost\Web\Exceptions\InvalidExportValue;
use WBoost\Web\Exceptions\InvalidRichTextValue;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;

/**
 * The structured error body every API render answers with — `{error, code,
 * ...context}` — so consumers react to the `code` (documented in
 * docs/api/consumer-prompt.md) instead of parsing messages. Group renders add
 * the `variantId` of the dimension that failed.
 */
final class ExportErrorResponse
{
    public static function invalidFormat(): JsonResponse
    {
        return new JsonResponse([
            'error' => 'format must be "png" or "pdf".',
            'code' => 'invalid_format',
        ], Response::HTTP_BAD_REQUEST);
    }

    public static function pdfNotAvailable(null|string $variantId = null): JsonResponse
    {
        return new JsonResponse([
            'error' => 'The print PDF exists only for print formats (mm / cm); this variant is a screen (px) format.',
            'code' => 'pdf_not_available',
            ...($variantId !== null ? ['variantId' => $variantId] : []),
        ], Response::HTTP_BAD_REQUEST);
    }

    /**
     * @return null|JsonResponse null when the throwable is not a render-contract failure (let it bubble)
     */
    public static function fromThrowable(Throwable $error, null|string $variantId = null): null|JsonResponse
    {
        $variantContext = $variantId !== null ? ['variantId' => $variantId] : [];

        if ($error instanceof ContainerOverflow) {
            return new JsonResponse([
                'error' => 'Container content overflows its max height. Shorten the texts of its inputs.',
                'code' => 'container_overflow',
                'containerId' => $error->containerId,
                'overflowPx' => round($error->overflowPx, 2),
                ...$variantContext,
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($error instanceof InvalidRichTextValue || $error instanceof InvalidExportValue) {
            return new JsonResponse([
                'error' => $error->getMessage(),
                'code' => $error->errorCode,
                ...$error->context,
                ...$variantContext,
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($error instanceof TemplateRenderUnavailable) {
            return new JsonResponse([
                'error' => 'The image renderer is overloaded and did not respond in time. Retry shortly.',
                'code' => 'render_unavailable',
                ...$variantContext,
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return null;
    }
}
