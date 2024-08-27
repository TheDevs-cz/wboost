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
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\FormData\SocialNetworkTemplateVariantEditorFormData;
use WBoost\Web\FormType\SocialNetworkTemplateVariantEditorFormType;
use WBoost\Web\Message\SocialNetwork\SaveSocialNetworkTemplateVariantEditor;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Services\Security\SocialNetworkTemplateVariantVoter;
use WBoost\Web\Services\Security\SocialNetworkTemplateVoter;
use WBoost\Web\Value\EditorTextInput;

final class SocialNetworkTemplateVariantEditorController extends AbstractController
{
    public function __construct(
        readonly private GetFonts $getFonts,
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/social-network-template-variant/{variantId}/editor', name: 'social_network_template_variant_editor')]
    #[IsGranted(SocialNetworkTemplateVariantVoter::EDIT, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        SocialNetworkTemplateVariant $variant,
        Request $request,
    ): Response {
        $template = $variant->template;
        $formData = new SocialNetworkTemplateVariantEditorFormData();
        $form = $this->createForm(SocialNetworkTemplateVariantEditorFormType::class, $formData);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            assert(is_string($formData->canvas));
            assert(is_string($formData->textInputs));

            $this->bus->dispatch(
                new SaveSocialNetworkTemplateVariantEditor(
                    $variant->id,
                    $formData->canvas,
                    EditorTextInput::createCollectionFromJson($formData->textInputs),
                ),
            );

            $this->addFlash('success', 'Editor uložen!');

            return $this->redirectToRoute('social_network_template_variants', [
                'templateId' => $template->id,
            ]);
        }

        return $this->render('social_network_template_variant_editor.html.twig', [
            'project' => $template->project,
            'template' => $template,
            'variant' => $variant,
            'fonts' => $this->getFonts->allForProject($template->project->id),
            'form' => $form,
        ]);
    }
}
