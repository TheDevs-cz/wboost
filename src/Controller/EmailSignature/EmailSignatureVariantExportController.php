<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\EmailSignature;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\EmailSignatureVariant;
use WBoost\Web\FormData\EmailSignatureVariantFormData;
use WBoost\Web\FormType\EmailSignatureVariantFormType;
use WBoost\Web\Message\EmailSignature\EditEmailSignatureVariant;
use WBoost\Web\Services\Security\EmailSignatureVariantVoter;

final class EmailSignatureVariantExportController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/email-signature-variant/{id}/export', name: 'export_email_signature_variant')]
    #[IsGranted(EmailSignatureVariantVoter::VIEW, 'emailVariant')]
    public function __invoke(Request $request, EmailSignatureVariant $emailVariant): Response
    {
        $data = new EmailSignatureVariantFormData();
        $data->name = $emailVariant->name;

        $form = $this->createForm(EmailSignatureVariantFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(
                new EditEmailSignatureVariant(
                    $emailVariant->id,
                    $data->name,
                ),
            );

            $this->addFlash('success', 'Varianta upravena!');

            return $this->redirectToRoute('edit_email_signature_variant', [
                'id' => $emailVariant->id,
            ]);
        }

        return $this->render('email_signature_variant_export.html.twig', [
            'project' => $emailVariant->template->project,
            'email_template' => $emailVariant->template,
            'variant' => $emailVariant,
            'form' => $form,
        ]);
    }
}
