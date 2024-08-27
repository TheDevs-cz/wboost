<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialNetwork;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Services\Security\SocialNetworkTemplateVariantVoter;

final class SocialNetworkTemplateVariantExportController extends AbstractController
{
    public function __construct(
        readonly private GetFonts $getFonts,
    ) {
    }

    #[Route(path: '/social-network-template-variant/{variantId}/export', name: 'social_network_template_variant_export')]
    #[IsGranted(SocialNetworkTemplateVariantVoter::VIEW, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        SocialNetworkTemplateVariant $variant,
    ): Response {
        return $this->render('social_network_template_variant_export.html.twig', [
            'project' => $variant->template->project,
            'template' => $variant->template,
            'variant' => $variant,
            'fonts' => $this->getFonts->allForProject($variant->template->project->id),
        ]);
    }
}
