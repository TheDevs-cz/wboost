<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialNetwork;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Services\Security\SocialNetworkTemplateVariantVoter;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;
use WBoost\Web\Services\SocialNetwork\SocialNetworkTemplateVariantImageRendererInterface;

/**
 * Stage 5 download endpoint.
 *
 * The user-fill page is the `SocialNetwork:VariantFiller` Live Component;
 * its export button is a regular form POST to this route. We chose a plain
 * form submit over a LiveAction redirect because Live Component's redirect
 * path goes through `Turbo.visit`, which does not reliably hand binary
 * responses back to the browser as a download (Turbo treats them as failed
 * navigations). A normal form with `data-turbo="false"` lets the browser
 * handle the response natively via Content-Disposition: attachment.
 *
 * The public API export endpoint (`/api/social-network-template-variants/
 * {id}/export`, served by `ExportProcessor`) is unaffected.
 */
final class SocialNetworkTemplateVariantDownloadController extends AbstractController
{
    public function __construct(
        private readonly SocialNetworkTemplateVariantImageRendererInterface $renderer,
        private readonly ResolveTextOverrides $resolveTextOverrides,
    ) {
    }

    #[Route(
        path: '/social-network-template-variant/{variantId}/download',
        name: 'social_network_template_variant_download',
        methods: ['POST'],
    )]
    #[IsGranted(SocialNetworkTemplateVariantVoter::VIEW, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        SocialNetworkTemplateVariant $variant,
        Request $request,
    ): Response {
        $rawTextValues = $request->request->all('textValues');
        $rawHiddenValues = $request->request->all('hiddenValues');

        /** @var array<string, array{value?: string, hide?: bool}> $providedValues */
        $providedValues = [];

        foreach ($rawTextValues as $inputId => $value) {
            if (!is_string($value)) {
                continue;
            }
            $providedValues[(string) $inputId] = ['value' => $value];
        }

        // HTML checkboxes only appear in $request->request when checked, so
        // every key present here represents an explicit "hide" selection.
        foreach ($rawHiddenValues as $inputId => $_) {
            $key = (string) $inputId;
            if (!isset($providedValues[$key])) {
                $providedValues[$key] = [];
            }
            $providedValues[$key]['hide'] = true;
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
