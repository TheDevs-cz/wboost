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
use WBoost\Web\Exceptions\InvalidDemoEmailAddresses;
use WBoost\Web\Message\EmailSignature\SendEmailSignatureDemo;
use WBoost\Web\Services\EmailSignature\DemoEmailAddressesParser;
use WBoost\Web\Services\Security\EmailSignatureVariantVoter;

final class SendEmailSignatureVariantDemoController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private DemoEmailAddressesParser $demoEmailAddressesParser,
    ) {
    }

    #[Route(path: '/email-signature-variant/{id}/send-demo', name: 'email_signature_variant_send_demo', methods: ['POST'])]
    #[IsGranted(EmailSignatureVariantVoter::VIEW, 'emailVariant')]
    public function __invoke(Request $request, EmailSignatureVariant $emailVariant): Response
    {
        try {
            $emails = $this->demoEmailAddressesParser->parse($request);
        } catch (InvalidDemoEmailAddresses $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectBack($request, $emailVariant);
        }

        $this->bus->dispatch(
            new SendEmailSignatureDemo(
                templateId: $emailVariant->template->id,
                variantId: $emailVariant->id,
                emails: $emails,
            ),
        );

        $this->addFlash('success', sprintf('Zkušební e-mail s podpisem odesíláme na: %s', implode(', ', $emails)));

        return $this->redirectBack($request, $emailVariant);
    }

    private function redirectBack(Request $request, EmailSignatureVariant $emailVariant): Response
    {
        $referer = $request->headers->get('referer');

        if ($referer !== null && str_starts_with($referer, $request->getSchemeAndHttpHost() . '/')) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('email_signature_variants', [
            'id' => $emailVariant->template->id,
        ]);
    }
}
