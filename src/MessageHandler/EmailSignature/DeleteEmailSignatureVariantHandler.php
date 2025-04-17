<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\EmailSignature;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\EmailSignatureVariantNotFound;
use WBoost\Web\Message\EmailSignature\DeleteEmailSignatureVariant;
use WBoost\Web\Repository\EmailSignatureVariantRepository;

#[AsMessageHandler]
readonly final class DeleteEmailSignatureVariantHandler
{
    public function __construct(
        private EmailSignatureVariantRepository $emailSignatureVariantRepository,
    ) {
    }

    /**
     * @throws EmailSignatureVariantNotFound
     */
    public function __invoke(DeleteEmailSignatureVariant $message): void
    {
        $emailSignatureVariant = $this->emailSignatureVariantRepository->get($message->variantId);

        $this->emailSignatureVariantRepository->remove($emailSignatureVariant);
    }
}
