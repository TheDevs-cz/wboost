<?php

declare(strict_types=1);

namespace WBoost\Web\Api\CustomTemplates;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use WBoost\Web\Exceptions\ContainerOverflow;
use WBoost\Web\Exceptions\InvalidRichTextValue;
use WBoost\Web\Exceptions\CustomTemplateVariantNotFound;
use WBoost\Web\Repository\CustomTemplateVariantRepository;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\Security\CustomTemplateVariantVoter;
use WBoost\Web\Services\SocialNetwork\ResolveImageOverrides;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;
use WBoost\Web\Services\Usage\RecordExportUsage;
use WBoost\Web\Value\ExportChannel;

/**
 * @implements ProcessorInterface<ExportRequest, Response>
 */
final readonly class ExportProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private CustomTemplateVariantRepository $variantRepository,
        private TemplateVariantImageRendererInterface $renderer,
        private ResolveTextOverrides $resolveTextOverrides,
        private ResolveRichTextOptions $resolveRichTextOptions,
        private ResolveImageOverrides $resolveImageOverrides,
        private RecordExportUsage $recordExportUsage,
    ) {
    }

    /**
     * @param ExportRequest $data
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $variantId = $uriVariables['id'] ?? null;

        if (!is_string($variantId) || !Uuid::isValid($variantId)) {
            throw new BadRequestHttpException('Invalid variant id.');
        }

        try {
            $variant = $this->variantRepository->get(Uuid::fromString($variantId));
        } catch (CustomTemplateVariantNotFound) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted(CustomTemplateVariantVoter::VIEW, $variant)) {
            throw new AccessDeniedHttpException();
        }

        try {
            $overrides = $this->resolveTextOverrides->resolve(
                $variant->inputs,
                $data->inputs,
                richTextOptions: $this->resolveRichTextOptions->forVariant($variant),
            );
        } catch (InvalidRichTextValue $invalidRichText) {
            // Structured 400 for rich-text contract violations — same pattern
            // as `container_overflow` below, so consumers can react to the
            // `code` (rich_text_not_allowed / invalid_rich_text /
            // font_not_allowed / invalid_color) programmatically.
            return new JsonResponse([
                'error' => $invalidRichText->getMessage(),
                'code' => $invalidRichText->errorCode,
                ...$invalidRichText->context,
            ], Response::HTTP_BAD_REQUEST);
        }

        $imageOverrides = $this->resolveImageOverrides->resolve(
            $variant->imageInputs,
            $variant->template->project->id,
            $data->images,
        );

        try {
            $response = $this->renderer->render($variant, $overrides, $imageOverrides, strictContainerOverflow: true);
        } catch (ContainerOverflow $overflow) {
            // Same contract class as the maxLength 400, but measured in pixels
            // of wrapped text. Returned as a structured JSON body (documented
            // in the OpenAPI schema + docs/api/consumer-prompt.md) so a
            // consumer can point the user at the offending container.
            return new JsonResponse([
                'error' => 'Container content overflows its max height. Shorten the texts of its inputs.',
                'code' => 'container_overflow',
                'containerId' => $overflow->containerId,
                'overflowPx' => round($overflow->overflowPx, 2),
            ], Response::HTTP_BAD_REQUEST);
        }
        $response->headers->set('Content-Type', 'image/png');
        $response->headers->set('Content-Disposition', sprintf('inline; filename="%s.png"', $variant->id->toString()));

        $this->recordExportUsage->record($variant, ExportChannel::Api);

        return $response;
    }
}
