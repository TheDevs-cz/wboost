<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Landing;

use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use WBoost\Web\Entity\LandingLead;
use WBoost\Web\Message\Landing\CaptureLandingLead;
use WBoost\Web\Repository\LandingLeadRepository;
use WBoost\Web\Services\ProvideIdentity;

#[AsMessageHandler]
readonly final class CaptureLandingLeadHandler
{
    /**
     * @param list<string> $landingLeadRecipients
     */
    public function __construct(
        private LandingLeadRepository $landingLeadRepository,
        private ProvideIdentity $provideIdentity,
        private ClockInterface $clock,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private Environment $twig,
        private array $landingLeadRecipients,
    ) {
    }

    public function __invoke(CaptureLandingLead $message): void
    {
        $lead = new LandingLead(
            $this->provideIdentity->next(),
            $message->source,
            $message->email,
            $this->clock->now(),
            name: $message->name,
            phone: $message->phone,
            company: $message->company,
            website: $message->website,
            interests: $message->interests,
            hasDesigner: $message->hasDesigner,
            designerName: $message->designerName,
            designerEmail: $message->designerEmail,
            message: $message->message,
        );
        $this->landingLeadRepository->save($lead);

        $html = $this->twig->render('emails/landing_lead.html.twig', [
            'lead' => $lead,
            'adminUrl' => $this->urlGenerator->generate('admin_landing_leads', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);

        $email = (new Email())
            ->to(...$this->landingLeadRecipients)
            ->replyTo(new Address($message->email, $message->name ?? ''))
            ->subject(sprintf('Nová poptávka z wboost.cz – %s', $message->name ?? $message->email))
            ->html($html);

        $this->mailer->send($email);
    }
}
