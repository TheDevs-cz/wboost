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
use WBoost\Web\Entity\SocialNetworkTemplate;
use WBoost\Web\FormData\SocialNetworkTemplateVariantFormData;
use WBoost\Web\FormType\SocialNetworkTemplateVariantFormType;
use WBoost\Web\Message\SocialNetwork\AddSocialNetworkTemplateVariant;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\SocialNetworkTemplateVoter;
use WBoost\Web\Value\TemplateDimension;

final class AddSocialNetworkTemplateVariantController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
    ) {
    }

    #[Route(path: '/social-network-template/{templateId}/add-variant/{dimension}', name: 'add_social_network_template_variant')]
    #[IsGranted(SocialNetworkTemplateVoter::EDIT, 'template')]
    public function __invoke(
        #[MapEntity(id: 'templateId')]
        SocialNetworkTemplate $template,
        TemplateDimension $dimension,
        Request $request,
    ): Response {
        $data = new SocialNetworkTemplateVariantFormData();
        $form = $this->createForm(SocialNetworkTemplateVariantFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $variantId = $this->provideIdentity->next();

            $this->bus->dispatch(
                new AddSocialNetworkTemplateVariant(
                    $template->id,
                    $variantId,
                    $dimension,
                    $data->backgroundImage,
                ),
            );

            return $this->redirectToRoute('social_network_template_variant_editor', [
                'variantId' => $variantId,
            ]);
        }

        return $this->render('add_social_network_template_variant.html.twig', [
            'form' => $form,
            'project' => $template->project,
            'template' => $template,
            'dimension' => $dimension,
        ]);
    }
}
