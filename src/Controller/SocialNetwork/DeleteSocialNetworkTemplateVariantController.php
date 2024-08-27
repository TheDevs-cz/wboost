<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialNetwork;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Message\SocialNetwork\DeleteSocialNetworkTemplateVariant;
use WBoost\Web\Services\Security\SocialNetworkTemplateVariantVoter;

final class DeleteSocialNetworkTemplateVariantController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/social-network-template-variant/{variantId}/delete', name: 'delete_social_network_template_variant')]
    #[IsGranted(SocialNetworkTemplateVariantVoter::EDIT, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        SocialNetworkTemplateVariant $variant,
    ): Response {
        $template = $variant->template;

        $this->bus->dispatch(
            new DeleteSocialNetworkTemplateVariant(
                $variant->id,
            ),
        );

        $this->addFlash('success', 'Varianta šablony smazána!');

        return $this->redirectToRoute('social_network_template_variants', [
            'templateId' => $template->id,
        ]);
    }
}
