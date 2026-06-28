<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\User;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\RegistrationRequestNotFound;
use WBoost\Web\Message\User\DismissRegistrationRequest;
use WBoost\Web\Repository\RegistrationRequestRepository;

#[AsMessageHandler]
readonly final class DismissRegistrationRequestHandler
{
    public function __construct(
        private RegistrationRequestRepository $registrationRequestRepository,
    ) {
    }

    /**
     * @throws RegistrationRequestNotFound
     */
    public function __invoke(DismissRegistrationRequest $message): void
    {
        $request = $this->registrationRequestRepository->getById(Uuid::fromString($message->registrationRequestId));

        $request->markDismissed();
    }
}
