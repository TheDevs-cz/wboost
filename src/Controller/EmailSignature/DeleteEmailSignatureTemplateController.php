<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\EmailSignature;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\EmailSignatureTemplate;
use WBoost\Web\Message\EmailSignature\DeleteEmailSignatureTemplate;
use WBoost\Web\Services\Security\EmailSignatureTemplateVoter;

final class DeleteEmailSignatureTemplateController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/delete-email-signature-template/{id}', name: 'delete_email_signature_template')]
    #[IsGranted(EmailSignatureTemplateVoter::EDIT, 'emailTemplate')]
    public function __invoke(EmailSignatureTemplate $emailTemplate): Response
    {
        $this->bus->dispatch(
            new DeleteEmailSignatureTemplate($emailTemplate->id),
        );

        $this->addFlash('success', 'Šablona smazána!');

        return $this->redirectToRoute('emails_list', [
            'id' => $emailTemplate->project->id,
        ]);
    }
}
