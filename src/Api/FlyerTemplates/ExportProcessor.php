<?php

declare(strict_types=1);

namespace WBoost\Web\Api\FlyerTemplates;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use WBoost\Web\Exceptions\FlyerTemplateVariantNotFound;
use WBoost\Web\Repository\FlyerTemplateVariantRepository;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\Security\FlyerTemplateVariantVoter;
use WBoost\Web\Services\SocialNetwork\ResolveImageOverrides;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;

/**
 * @implements ProcessorInterface<ExportRequest, Response>
 */
final readonly class ExportProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private FlyerTemplateVariantRepository $variantRepository,
        private TemplateVariantImageRendererInterface $renderer,
        private ResolveTextOverrides $resolveTextOverrides,
        private ResolveImageOverrides $resolveImageOverrides,
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
        } catch (FlyerTemplateVariantNotFound) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted(FlyerTemplateVariantVoter::VIEW, $variant)) {
            throw new AccessDeniedHttpException();
        }

        $overrides = $this->resolveTextOverrides->resolve($variant->inputs, $data->inputs);
        $imageOverrides = $this->resolveImageOverrides->resolve(
            $variant->imageInputs,
            $variant->template->project->id,
            $data->images,
        );

        $response = $this->renderer->render($variant, $overrides, $imageOverrides);
        $response->headers->set('Content-Type', 'image/png');
        $response->headers->set('Content-Disposition', sprintf('inline; filename="%s.png"', $variant->id->toString()));

        return $response;
    }
}
