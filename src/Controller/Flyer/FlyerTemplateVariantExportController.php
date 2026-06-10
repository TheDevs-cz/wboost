<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Flyer;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\FlyerTemplateVariant;
use WBoost\Web\Services\Security\FlyerTemplateVariantVoter;

final class FlyerTemplateVariantExportController extends AbstractController
{
    #[Route(path: '/flyer-template-variant/{variantId}/export', name: 'flyer_template_variant_export')]
    #[IsGranted(FlyerTemplateVariantVoter::VIEW, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        FlyerTemplateVariant $variant,
    ): Response {
        // The user-fill UI is the `Flyer:VariantFiller` Live Component; the
        // preview renders server-side through the same Gotenberg pipeline as
        // the API export endpoint.
        $template = $variant->template;

        return $this->render('flyer_template_variant_export.html.twig', [
            'project' => $template->project,
            'template' => $template,
            'variant' => $variant,
        ]);
    }
}
