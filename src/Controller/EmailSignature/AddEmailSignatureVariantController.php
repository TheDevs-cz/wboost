<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\EmailSignature;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\EmailSignatureTemplate;
use WBoost\Web\FormData\EmailSignatureVariantFormData;
use WBoost\Web\FormType\EmailSignatureVariantFormType;
use WBoost\Web\Message\EmailSignature\AddEmailSignatureVariant;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\EmailSignatureTemplateVoter;

final class AddEmailSignatureVariantController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
    ) {
    }

    #[Route(path: '/email-signature/{id}/add-variant', name: 'add_email_signature_variant')]
    #[IsGranted(EmailSignatureTemplateVoter::VIEW, 'template')]
    public function __invoke(EmailSignatureTemplate $template, Request $request): Response
    {
        $data = new EmailSignatureVariantFormData();
        $form = $this->createForm(EmailSignatureVariantFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $variantId = $this->provideIdentity->next();

            $this->bus->dispatch(
                new AddEmailSignatureVariant(
                    variantId: $variantId,
                    templateId: $template->id,
                    name: $data->name,
                ),
            );

            return $this->redirectToRoute('edit_email_signature_variant', [
                'id' => $variantId,
            ]);
        }

        return $this->render('add_email_signature_variant.html.twig', [
            'form' => $form,
            'project' => $template->project,
            'email_template' => $template,
        ]);
    }
}
