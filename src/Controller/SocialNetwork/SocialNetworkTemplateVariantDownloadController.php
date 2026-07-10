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
use WBoost\Web\Services\SocialNetwork\FillFormRequestParser;
use WBoost\Web\Services\SocialNetwork\ResolveImageOverrides;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\Usage\RecordExportUsage;
use WBoost\Web\Value\ExportChannel;

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
 * {id}/export`, served by `ExportProcessor`) is unaffected. The direct
 * social publish endpoint (`SocialNetworkTemplateVariantPublishController`)
 * consumes the identical POST body via the shared FillFormRequestParser.
 */
final class SocialNetworkTemplateVariantDownloadController extends AbstractController
{
    public function __construct(
        private readonly TemplateVariantImageRendererInterface $renderer,
        private readonly ResolveTextOverrides $resolveTextOverrides,
        private readonly ResolveRichTextOptions $resolveRichTextOptions,
        private readonly ResolveImageOverrides $resolveImageOverrides,
        private readonly FillFormRequestParser $fillFormParser,
        private readonly RecordExportUsage $recordExportUsage,
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
        $overrides = $this->resolveTextOverrides->resolve(
            $variant->inputs,
            $this->fillFormParser->parseTextValues($request),
            truncateOverflow: true,
            richTextOptions: $this->resolveRichTextOptions->forVariant($variant),
        );
        $imageOverrides = $this->resolveImageOverrides->resolve(
            $variant->imageInputs,
            $variant->template->project->id,
            $this->fillFormParser->parseImageValues($request),
        );

        $response = $this->renderer->render($variant, $overrides, $imageOverrides);
        $response->headers->set('Content-Type', 'image/png');
        $response->headers->set('Content-Disposition', sprintf(
            'attachment; filename="%s.png"',
            $variant->id->toString(),
        ));

        $this->recordExportUsage->record($variant, ExportChannel::Web);

        return $response;
    }
}
