<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialNetwork;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\FormData\SocialNetworkTemplateVariantFormData;
use WBoost\Web\FormType\SocialNetworkTemplateVariantFormType;
use WBoost\Web\Message\SocialNetwork\AddSocialNetworkTemplateVariant;
use WBoost\Web\Message\SocialNetwork\CopySocialNetworkTemplateVariant;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\SocialNetworkTemplateVariantVoter;
use WBoost\Web\Value\TemplateDimension;

final class DuplicateSocialNetworkTemplateVariantController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
    ) {
    }

    #[Route(path: '/social-network-template-variant/{variantId}/copy/{dimension}', name: 'copy_social_network_template_variant')]
    #[IsGranted(SocialNetworkTemplateVariantVoter::EDIT, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        SocialNetworkTemplateVariant $variant,
        TemplateDimension $dimension,
        Request $request,
    ): Response {
        $newId = $this->provideIdentity->next();

        $this->bus->dispatch(
            new CopySocialNetworkTemplateVariant(
                $variant->id,
                $newId,
                $dimension,
            ),
        );

        $this->addFlash('success', 'Varianta šablony zduplikována.');

        return $this->redirectToRoute('social_network_template_variants', [
            'templateId' => $variant->template->id,
        ]);
    }
}
