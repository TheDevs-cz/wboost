<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Flyer;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\FlyerTemplate;
use WBoost\Web\FormData\FlyerTemplateVariantFormData;
use WBoost\Web\FormType\FlyerTemplateVariantFormType;
use WBoost\Web\Message\Flyer\AddFlyerTemplateVariant;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\FlyerTemplateVoter;

/**
 * Unlike social network variants (whose dimension is a fixed enum chosen via
 * the URL), a flyer variant's dimension is free-form: the designer picks the
 * unit (px / mm / cm) and the width × height in the creation form, with A5 /
 * A4 / A3 one-click presets prefilling millimetre values.
 */
final class AddFlyerTemplateVariantController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
    ) {
    }

    #[Route(path: '/flyer-template/{templateId}/add-variant', name: 'add_flyer_template_variant')]
    #[IsGranted(FlyerTemplateVoter::EDIT, 'template')]
    public function __invoke(
        #[MapEntity(id: 'templateId')]
        FlyerTemplate $template,
        Request $request,
    ): Response {
        $data = new FlyerTemplateVariantFormData();
        $form = $this->createForm(FlyerTemplateVariantFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $variantId = $this->provideIdentity->next();

            $this->bus->dispatch(
                new AddFlyerTemplateVariant(
                    $template->id,
                    $variantId,
                    $data->dimension(),
                    $data->backgroundImage,
                ),
            );

            return $this->redirectToRoute('flyer_template_variant_editor', [
                'variantId' => $variantId,
            ]);
        }

        return $this->render('add_flyer_template_variant.html.twig', [
            'form' => $form,
            'project' => $template->project,
            'template' => $template,
        ]);
    }
}
