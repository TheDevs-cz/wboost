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
use WBoost\Web\Message\Template\EditTemplateVariantPrintSettings;
use WBoost\Web\Services\Security\TemplateVariantVoter;

/**
 * Saves the "Nastavení tisku" modal of an existing variant (the add forms set
 * the print setup at creation). A GET lands on the variants page, which holds
 * the modal; old links to the former standalone page keep working.
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

        if (!$request->isMethod('POST')) {
            return $this->redirectToRoute('template_variants', ['templateId' => $variant->template->id]);
        }

        if (!$this->isCsrfTokenValid('template_variant_print_settings', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->bus->dispatch(new EditTemplateVariantPrintSettings(
            $variant->id,
            (float) str_replace(',', '.', $request->request->getString('bleed', '0')),
            $request->request->getBoolean('cropMarks'),
        ));

        $this->addFlash('success', 'Nastavení tisku bylo uloženo.');

        return $this->redirectToRoute('template_variants', ['templateId' => $variant->template->id]);
    }
}
