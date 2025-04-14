<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\EmailSignature;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\EmailSignatureTemplateNotFound;
use WBoost\Web\Message\EmailSignature\DeleteEmailSignatureTemplate;
use WBoost\Web\Repository\EmailSignatureTemplateRepository;

#[AsMessageHandler]
readonly final class DeleteEmailSignatureTemplateHandler
{
    public function __construct(
        private EmailSignatureTemplateRepository $emailSignatureTemplateRepository,
    ) {
    }

    /**
     * @throws EmailSignatureTemplateNotFound
     */
    public function __invoke(DeleteEmailSignatureTemplate $message): void
    {
        $emailSignatureTemplate = $this->emailSignatureTemplateRepository->get($message->emailSignatureTemplateId);

        $this->emailSignatureTemplateRepository->remove($emailSignatureTemplate);
    }
}
