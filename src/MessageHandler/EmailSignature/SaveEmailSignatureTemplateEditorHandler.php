<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\EmailSignature;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\EmailSignatureTemplateNotFound;
use WBoost\Web\Message\EmailSignature\SaveEmailSignatureTemplateEditor;
use WBoost\Web\Repository\EmailSignatureTemplateRepository;

#[AsMessageHandler]
readonly final class SaveEmailSignatureTemplateEditorHandler
{
    public function __construct(
        private EmailSignatureTemplateRepository $emailSignatureTemplateRepository,
    ) {
    }

    /**
     * @throws EmailSignatureTemplateNotFound
     */
    public function __invoke(SaveEmailSignatureTemplateEditor $message): void
    {
        $emailTemplate = $this->emailSignatureTemplateRepository->get($message->templateId);

        $emailTemplate->changeCode(
            $message->code,
            $message->textInputs,
        );
    }
}
