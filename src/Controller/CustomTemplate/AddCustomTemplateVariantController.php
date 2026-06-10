<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\CustomTemplate;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\CustomTemplate;
use WBoost\Web\FormData\CustomTemplateVariantFormData;
use WBoost\Web\FormType\CustomTemplateVariantFormType;
use WBoost\Web\Message\CustomTemplate\AddCustomTemplateVariant;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\CustomTemplateVoter;

/**
 * Unlike social network variants (whose dimension is a fixed enum chosen via
 * the URL), a custom-template variant's dimension is free-form: the designer picks the
 * unit (px / mm / cm) and the width × height in the creation form, with A5 /
 * A4 / A3 one-click presets prefilling millimetre values.
 */
final class AddCustomTemplateVariantController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
    ) {
    }

    #[Route(path: '/custom-template/{templateId}/add-variant', name: 'add_custom_template_variant')]
    #[IsGranted(CustomTemplateVoter::EDIT, 'template')]
    public function __invoke(
        #[MapEntity(id: 'templateId')]
        CustomTemplate $template,
        Request $request,
    ): Response {
        $data = new CustomTemplateVariantFormData();
        $form = $this->createForm(CustomTemplateVariantFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $variantId = $this->provideIdentity->next();

            $this->bus->dispatch(
                new AddCustomTemplateVariant(
                    $template->id,
                    $variantId,
                    $data->dimension(),
                    $data->backgroundImage,
                ),
            );

            return $this->redirectToRoute('custom_template_variant_editor', [
                'variantId' => $variantId,
            ]);
        }

        return $this->render('add_custom_template_variant.html.twig', [
            'form' => $form,
            'project' => $template->project,
            'template' => $template,
        ]);
    }
}
