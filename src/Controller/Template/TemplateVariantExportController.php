<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Template;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Entity\User;
use WBoost\Web\Services\Meta\GetFacebookDestinations;
use WBoost\Web\Services\Security\TemplateVariantVoter;

final class TemplateVariantExportController extends AbstractController
{
    public function __construct(
        readonly private GetFacebookDestinations $destinations,
    ) {
    }

    #[Route(path: '/template-variant/{variantId}/export', name: 'template_variant_export')]
    #[IsGranted(TemplateVariantVoter::VIEW, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        TemplateVariant $variant,
        #[CurrentUser] User $user,
    ): Response {
        // The user-fill UI is the `Template:VariantFiller` Live Component; the
        // preview renders server-side through the same Gotenberg pipeline as
        // the API export endpoint.
        $template = $variant->template;

        return $this->render('template_variant_export.html.twig', [
            'project' => $template->project,
            'template' => $template,
            'variant' => $variant,
            'facebookConnected' => $this->destinations->connectedAccount($user) !== null,
        ]);
    }
}
