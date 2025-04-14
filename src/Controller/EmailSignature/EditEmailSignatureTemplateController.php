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
use WBoost\Web\Entity\Manual;
use WBoost\Web\FormData\EmailSignatureTemplateFormData;
use WBoost\Web\FormData\ManualFormData;
use WBoost\Web\FormType\EmailSignatureTemplateFormType;
use WBoost\Web\FormType\ManualFormType;
use WBoost\Web\Message\EmailSignature\EditEmailSignatureTemplate;
use WBoost\Web\Message\Manual\EditManual;
use WBoost\Web\Services\Security\EmailSignatureTemplateVoter;

final class EditEmailSignatureTemplateController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/edit-email-signature-template/{id}', name: 'edit_email_signature_template')]
    #[IsGranted(EmailSignatureTemplateVoter::EDIT, 'emailTemplate')]
    public function __invoke(Request $request, EmailSignatureTemplate $emailTemplate): Response
    {
        $data = new EmailSignatureTemplateFormData();
        $data->name = $emailTemplate->name;

        $form = $this->createForm(EmailSignatureTemplateFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(
                new EditEmailSignatureTemplate(
                    $emailTemplate->id,
                    $data->type,
                    $data->name,
                    $data->introImage,
                ),
            );

            $this->addFlash('success', 'Manuál upraven!');

            return $this->redirectToRoute('manual_dashboard', [
                'id' => $emailTemplate->id->toString(),
            ]);
        }

        return $this->render('edit_manual.html.twig', [
            'project' => $emailTemplate->project,
            'manual' => $emailTemplate,
            'form' => $form,
        ]);
    }
}
