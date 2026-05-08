<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialNetwork;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Services\Security\SocialNetworkTemplateVariantVoter;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;
use WBoost\Web\Services\SocialNetwork\SocialNetworkTemplateVariantImageRendererInterface;

/**
 * Stage 5 download endpoint. The user-fill page is now a Live Component
 * (`SocialNetwork:VariantFiller`); since LiveActions cannot return file
 * responses directly (per the bundle docs), the component's "Export PNG"
 * action stashes the current input state in the session and redirects here.
 *
 * This controller pops that session bag, runs the same renderer pipeline used
 * by the API, and streams the PNG back as an attachment. The session bag is
 * deleted on read to keep state one-shot.
 *
 * The legacy POST `/render` route this replaces was an in-app preview/render
 * endpoint called by client-side Fabric — it is gone with the JS controller.
 * The public API export endpoint (`/api/social-network-template-variants/
 * {id}/export`, served by `ExportProcessor`) is unaffected.
 */
final class SocialNetworkTemplateVariantDownloadController extends AbstractController
{
    public function __construct(
        private readonly SocialNetworkTemplateVariantImageRendererInterface $renderer,
        private readonly ResolveTextOverrides $resolveTextOverrides,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Build the session key under which the Live Component stashes input
     * state for a given variant. Centralised so both sides stay in sync.
     */
    public static function sessionKey(string $variantId): string
    {
        return 'social_network_variant_download.' . $variantId;
    }

    #[Route(
        path: '/social-network-template-variant/{variantId}/download',
        name: 'social_network_template_variant_download',
        methods: ['GET'],
    )]
    #[IsGranted(SocialNetworkTemplateVariantVoter::VIEW, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        SocialNetworkTemplateVariant $variant,
    ): Response {
        $session = $this->requestStack->getSession();
        $key = self::sessionKey($variant->id->toString());

        /** @var mixed $payload */
        $payload = $session->get($key, []);
        $session->remove($key);

        $textValues = [];
        $hiddenValues = [];

        if (is_array($payload)) {
            if (isset($payload['textValues']) && is_array($payload['textValues'])) {
                /** @var array<string, string> $textValues */
                $textValues = $payload['textValues'];
            }
            if (isset($payload['hiddenValues']) && is_array($payload['hiddenValues'])) {
                /** @var array<string, bool> $hiddenValues */
                $hiddenValues = $payload['hiddenValues'];
            }
        }

        /** @var array<string, array{value?: string, hide?: bool}> $providedValues */
        $providedValues = [];

        foreach ($textValues as $inputId => $value) {
            $providedValues[$inputId] = ['value' => $value];
        }
        foreach ($hiddenValues as $inputId => $hide) {
            if (!isset($providedValues[$inputId])) {
                $providedValues[$inputId] = [];
            }
            $providedValues[$inputId]['hide'] = $hide;
        }

        $overrides = $this->resolveTextOverrides->resolve($variant->inputs, $providedValues);

        $response = $this->renderer->render($variant, $overrides);
        $response->headers->set('Content-Type', 'image/png');
        $response->headers->set('Content-Disposition', sprintf(
            'attachment; filename="%s.png"',
            $variant->id->toString(),
        ));

        return $response;
    }
}
