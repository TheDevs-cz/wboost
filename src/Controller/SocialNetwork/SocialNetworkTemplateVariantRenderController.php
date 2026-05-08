<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialNetwork;

use JsonException;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Services\Security\SocialNetworkTemplateVariantVoter;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;
use WBoost\Web\Services\SocialNetwork\SocialNetworkTemplateVariantImageRendererInterface;

final class SocialNetworkTemplateVariantRenderController extends AbstractController
{
    public function __construct(
        private readonly SocialNetworkTemplateVariantImageRendererInterface $renderer,
        private readonly ResolveTextOverrides $resolveTextOverrides,
    ) {
    }

    #[Route(
        path: '/social-network-template-variant/{variantId}/render',
        name: 'social_network_template_variant_render',
        methods: ['POST'],
    )]
    #[IsGranted(SocialNetworkTemplateVariantVoter::VIEW, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        SocialNetworkTemplateVariant $variant,
        Request $request,
    ): Response {
        try {
            /** @var mixed $payload */
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new BadRequestHttpException('Invalid JSON body.', $e);
        }

        $inputs = [];

        if (is_array($payload) && isset($payload['inputs']) && is_array($payload['inputs'])) {
            /** @var array<string, mixed> $inputs */
            $inputs = $payload['inputs'];
        }

        $overrides = $this->resolveTextOverrides->resolve($variant->inputs, $inputs);

        $response = $this->renderer->render($variant, $overrides);
        $response->headers->set('Content-Type', 'image/png');
        $response->headers->set('Content-Disposition', sprintf('inline; filename="%s.png"', $variant->id->toString()));

        return $response;
    }
}
