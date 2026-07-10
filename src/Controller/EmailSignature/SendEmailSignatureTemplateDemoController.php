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
use WBoost\Web\Exceptions\InvalidDemoEmailAddresses;
use WBoost\Web\Message\EmailSignature\SendEmailSignatureDemo;
use WBoost\Web\Services\EmailSignature\DemoEmailAddressesParser;
use WBoost\Web\Services\Security\EmailSignatureTemplateVoter;

final class SendEmailSignatureTemplateDemoController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private DemoEmailAddressesParser $demoEmailAddressesParser,
    ) {
    }

    #[Route(path: '/email-signature/{id}/send-demo', name: 'email_signature_template_send_demo', methods: ['POST'])]
    #[IsGranted(EmailSignatureTemplateVoter::VIEW, 'template')]
    public function __invoke(Request $request, EmailSignatureTemplate $template): Response
    {
        try {
            $emails = $this->demoEmailAddressesParser->parse($request);
        } catch (InvalidDemoEmailAddresses $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectBack($request, $template);
        }

        $this->bus->dispatch(
            new SendEmailSignatureDemo(
                templateId: $template->id,
                variantId: null,
                emails: $emails,
            ),
        );

        $this->addFlash('success', sprintf('Zkušební e-mail s podpisem odesíláme na: %s', implode(', ', $emails)));

        return $this->redirectBack($request, $template);
    }

    private function redirectBack(Request $request, EmailSignatureTemplate $template): Response
    {
        $referer = $request->headers->get('referer');

        if ($referer !== null && str_starts_with($referer, $request->getSchemeAndHttpHost() . '/')) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('email_signature_variants', [
            'id' => $template->id,
        ]);
    }
}
