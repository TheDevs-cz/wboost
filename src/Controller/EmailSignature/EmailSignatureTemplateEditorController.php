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
use WBoost\Web\FormData\EmailSignatureTemplateEditorFormData;
use WBoost\Web\FormType\EmailSignatureTemplateEditorFormType;
use WBoost\Web\Message\EmailSignature\SaveEmailSignatureTemplateEditor;
use WBoost\Web\Services\Security\EmailSignatureTemplateVoter;
use WBoost\Web\Value\EmailTextInput;

final class EmailSignatureTemplateEditorController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/email-signature-template-editor/{id}', name: 'email_signature_template_editor')]
    #[IsGranted(EmailSignatureTemplateVoter::EDIT, 'emailTemplate')]
    public function __invoke(Request $request, EmailSignatureTemplate $emailTemplate): Response
    {
        $formData = new EmailSignatureTemplateEditorFormData();
        $editorForm = $this->createForm(EmailSignatureTemplateEditorFormType::class, $formData);

        $editorForm->handleRequest($request);

        if ($editorForm->isSubmitted() && $editorForm->isValid()) {
            $this->bus->dispatch(
                new SaveEmailSignatureTemplateEditor(
                    $emailTemplate->id,
                    $formData->code,
                    EmailTextInput::createCollectionFromJson($formData->textPlaceholders),
                ),
            );

            if ($request->headers->get('accept') === 'application/json') {
                return $this->json([
                    'status' => 'success',
                ]);
            }

            $this->addFlash('success', 'Editor uložen!');

            return $this->redirectToRoute('email_signature_template_editor', [
                'templateId' => $emailTemplate->id,
            ]);
        }

        return $this->render('email_signature_template_editor.html.twig', [
            'project' => $emailTemplate->project,
            'email_template' => $emailTemplate,
            'editor_form' => $editorForm,
        ]);
    }
}
