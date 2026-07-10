<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\EmailSignature;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use WBoost\Web\Exceptions\EmailSignatureTemplateNotFound;
use WBoost\Web\Exceptions\EmailSignatureVariantNotFound;
use WBoost\Web\Message\EmailSignature\SendEmailSignatureDemo;
use WBoost\Web\Repository\EmailSignatureTemplateRepository;
use WBoost\Web\Repository\EmailSignatureVariantRepository;
use WBoost\Web\Services\EmailSignature\EmailSignatureHtmlComposer;

#[AsMessageHandler]
readonly final class SendEmailSignatureDemoHandler
{
    public function __construct(
        private EmailSignatureTemplateRepository $emailSignatureTemplateRepository,
        private EmailSignatureVariantRepository $emailSignatureVariantRepository,
        private EmailSignatureHtmlComposer $htmlComposer,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private Environment $twig,
    ) {
    }

    /**
     * @throws EmailSignatureTemplateNotFound
     * @throws EmailSignatureVariantNotFound
     */
    public function __invoke(SendEmailSignatureDemo $message): void
    {
        $template = $this->emailSignatureTemplateRepository->get($message->templateId);

        $variant = null;

        if ($message->variantId !== null) {
            $variant = $this->emailSignatureVariantRepository->get($message->variantId);

            $vcardQrCodeUrl = $this->urlGenerator->generate('email_signature_variant_vcard_qr_code', [
                'variantId' => $variant->id->toString(),
            ], UrlGeneratorInterface::ABSOLUTE_URL);
        } else {
            // Template demo has no variant to build a vCard from — substitute
            // a sample QR so the layout still shows a scannable code.
            $vcardQrCodeUrl = $this->urlGenerator->generate(
                'email_signature_demo_vcard_qr_code',
                referenceType: UrlGeneratorInterface::ABSOLUTE_URL,
            );
        }

        $signatureName = $variant !== null
            ? sprintf('%s – %s', $template->name, $variant->name)
            : $template->name;

        $html = $this->twig->render('emails/email_signature_demo.html.twig', [
            'signatureName' => $signatureName,
            'signatureHtml' => $this->htmlComposer->compose($template, $variant, $vcardQrCodeUrl),
        ]);

        foreach ($message->emails as $recipient) {
            $email = (new Email())
                ->to($recipient)
                ->subject('Ukázka e-mailového podpisu – ' . $signatureName)
                ->html($html);

            $this->mailer->send($email);
        }
    }
}
