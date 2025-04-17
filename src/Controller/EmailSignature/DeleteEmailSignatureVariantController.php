<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\EmailSignature;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\EmailSignatureVariant;
use WBoost\Web\Message\EmailSignature\DeleteEmailSignatureVariant;
use WBoost\Web\Services\Security\EmailSignatureVariantVoter;

final class DeleteEmailSignatureVariantController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/delete-email-signature-variant/{id}', name: 'delete_email_signature_variant')]
    #[IsGranted(EmailSignatureVariantVoter::VIEW, 'emailVariant')]
    public function __invoke(EmailSignatureVariant $emailVariant): Response
    {
        $this->bus->dispatch(
            new DeleteEmailSignatureVariant($emailVariant->id),
        );

        $this->addFlash('success', 'Varianta smazána!');

        return $this->redirectToRoute('email_signature_variants', [
            'id' => $emailVariant->template->id,
        ]);
    }
}
