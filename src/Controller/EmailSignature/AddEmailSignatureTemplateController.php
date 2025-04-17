<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\EmailSignature;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\FormData\EmailSignatureTemplateFormData;
use WBoost\Web\FormType\EmailSignatureTemplateFormType;
use WBoost\Web\Message\EmailSignature\AddEmailSignatureTemplate;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\ProjectVoter;

final class AddEmailSignatureTemplateController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
    ) {
    }

    #[Route(path: '/project/{id}/add-email-signature-template', name: 'add_email_signature_template')]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(
        #[CurrentUser] UserInterface $user,
        Request $request,
        Project $project,
    ): Response {
        $data = new EmailSignatureTemplateFormData();
        $form = $this->createForm(EmailSignatureTemplateFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $emailSignatureTemplateId = $this->provideIdentity->next();

            $this->bus->dispatch(
                new AddEmailSignatureTemplate(
                    templateId: $emailSignatureTemplateId,
                    projectId: $project->id,
                    name: $data->name,
                    backgroundImage: $data->backgroundImage,
                ),
            );

            return $this->redirectToRoute('email_signature_template_editor', [
                'id' => $emailSignatureTemplateId,
            ]);
        }

        return $this->render('add_email_signature_template.html.twig', [
            'form' => $form,
            'project' => $project,
        ]);
    }
}
