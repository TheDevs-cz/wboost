<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\EmailSignature;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\EmailSignatureVariantNotFound;
use WBoost\Web\Message\EmailSignature\EditEmailSignatureVariant;
use WBoost\Web\Repository\EmailSignatureVariantRepository;

#[AsMessageHandler]
readonly final class EditEmailSignatureVariantHandler
{
    public function __construct(
        private EmailSignatureVariantRepository $emailSignatureVariantRepository,
    ) {
    }

    /**
     * @throws EmailSignatureVariantNotFound
     */
    public function __invoke(EditEmailSignatureVariant $message): void
    {
        $emailVariant = $this->emailSignatureVariantRepository->get($message->variantId);

        $emailVariant->edit(
            $message->name,
        );
    }
}
