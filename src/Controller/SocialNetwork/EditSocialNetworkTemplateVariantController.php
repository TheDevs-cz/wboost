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
use WBoost\Web\Message\SocialNetwork\EditSocialNetworkTemplateVariant;
use WBoost\Web\Repository\SocialNetworkTemplateVariantRepository;
use WBoost\Web\Services\Security\SocialNetworkTemplateVariantVoter;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\FileSource;

final class EditSocialNetworkTemplateVariantController extends AbstractController
{
    public function __construct(
        readonly private SocialNetworkTemplateVariantRepository $variantRepository,
        readonly private MessageBusInterface $bus,
        readonly private UploaderHelper $uploaderHelper,
    ) {
    }

    #[Route(path: '/social-network-template-variant/{variantId}/edit', name: 'edit_social_network_template_variant')]
    #[IsGranted(SocialNetworkTemplateVariantVoter::EDIT, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        SocialNetworkTemplateVariant $variant,
        Request $request,
    ): Response {
        $data = new SocialNetworkTemplateVariantFormData();
        $form = $this->createForm(SocialNetworkTemplateVariantFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(
                new EditSocialNetworkTemplateVariant(
                    $variant->id,
                    $data->backgroundImage,
                ),
            );

            // Get fresh one
            $variant = $this->variantRepository->get($variant->id);

            return $this->json(['filePath' => $this->uploaderHelper->getPublicPath($variant->backgroundImage)]);
        }

        return $this->json(['error' => 'Invalid form submission'], 400);
    }
}
