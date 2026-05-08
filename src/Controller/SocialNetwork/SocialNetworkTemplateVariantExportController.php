<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialNetwork;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Services\Security\SocialNetworkTemplateVariantVoter;

final class SocialNetworkTemplateVariantExportController extends AbstractController
{
    #[Route(path: '/social-network-template-variant/{variantId}/export', name: 'social_network_template_variant_export')]
    #[IsGranted(SocialNetworkTemplateVariantVoter::VIEW, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        SocialNetworkTemplateVariant $variant,
    ): Response {
        // Stage 5: the user-fill UI is now a Live Component
        // (`SocialNetwork:VariantFiller`). Fonts and canvas JSON are no longer
        // needed in this view — the component renders its preview server-side
        // through the same Gotenberg pipeline as the API export endpoint.
        $template = $variant->template;

        return $this->render('social_network_template_variant_export.html.twig', [
            'project' => $template->project,
            'template' => $template,
            'variant' => $variant,
        ]);
    }
}
