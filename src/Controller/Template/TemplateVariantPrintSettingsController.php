<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Template;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\FormData\TemplateVariantPrintSettingsFormData;
use WBoost\Web\FormType\TemplateVariantPrintSettingsFormType;
use WBoost\Web\Message\Template\EditTemplateVariantPrintSettings;
use WBoost\Web\Services\Security\TemplateVariantVoter;

/**
 * "Tiskové nastavení" — bleed + crop marks of an existing variant (the
 * add forms set them at creation; before this page they were fixed).
 */
final class TemplateVariantPrintSettingsController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/template-variant/{variantId}/print-settings', name: 'template_variant_print_settings', methods: ['GET', 'POST'])]
    #[IsGranted(TemplateVariantVoter::EDIT, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        TemplateVariant $variant,
        Request $request,
    ): Response {
        if (!$variant->dimension->isPrintFormat()) {
            throw $this->createNotFoundException('Print settings exist only for print (mm / cm) formats.');
        }

        $data = TemplateVariantPrintSettingsFormData::fromDimension($variant->dimension);
        $form = $this->createForm(TemplateVariantPrintSettingsFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(new EditTemplateVariantPrintSettings(
                $variant->id,
                $data->bleed ?? 0.0,
                $data->cropMarks,
            ));

            $this->addFlash('success', 'Tiskové nastavení bylo uloženo.');

            return $this->redirectToRoute('template_variants', ['templateId' => $variant->template->id]);
        }

        return $this->render('template_variant_print_settings.html.twig', [
            'form' => $form,
            'variant' => $variant,
            'template' => $variant->template,
            'project' => $variant->template->project,
        ]);
    }
}
