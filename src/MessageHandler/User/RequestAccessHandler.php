<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\User;

use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use WBoost\Web\Entity\RegistrationRequest;
use WBoost\Web\Exceptions\AccessAlreadyRequested;
use WBoost\Web\Exceptions\EmailAlreadyRegistered;
use WBoost\Web\Message\User\RequestAccess;
use WBoost\Web\Repository\RegistrationRequestRepository;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Services\ProvideIdentity;

#[AsMessageHandler]
readonly final class RequestAccessHandler
{
    /**
     * @param list<string> $signupNotificationRecipients
     */
    public function __construct(
        private UserRepository $userRepository,
        private RegistrationRequestRepository $registrationRequestRepository,
        private ProvideIdentity $provideIdentity,
        private ClockInterface $clock,
        private MailerInterface $mailer,
        private Environment $twig,
        private array $signupNotificationRecipients,
    ) {
    }

    /**
     * @throws EmailAlreadyRegistered
     * @throws AccessAlreadyRequested
     */
    public function __invoke(RequestAccess $message): void
    {
        $existingUser = $this->userRepository->findByEmailOrNull($message->email);
        if ($existingUser !== null && $existingUser->confirmed) {
            throw new EmailAlreadyRegistered();
        }

        if ($this->registrationRequestRepository->findPendingByEmail($message->email) !== null) {
            throw new AccessAlreadyRequested();
        }

        $request = new RegistrationRequest(
            $this->provideIdentity->next(),
            $message->email,
            $this->clock->now(),
        );
        $this->registrationRequestRepository->save($request);

        $html = $this->twig->render('emails/access_request.html.twig', [
            'requesterEmail' => $message->email,
        ]);

        $email = (new Email())
            ->to(...$this->signupNotificationRecipients)
            ->subject('Nová žádost o registraci do WBoost')
            ->html($html);

        $this->mailer->send($email);
    }
}
