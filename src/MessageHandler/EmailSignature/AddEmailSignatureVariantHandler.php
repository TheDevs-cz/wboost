<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\EmailSignature;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\EmailSignatureVariant;
use WBoost\Web\Exceptions\EmailSignatureTemplateNotFound;
use WBoost\Web\Message\EmailSignature\AddEmailSignatureVariant;
use WBoost\Web\Repository\EmailSignatureTemplateRepository;
use WBoost\Web\Repository\EmailSignatureVariantRepository;

#[AsMessageHandler]
readonly final class AddEmailSignatureVariantHandler
{
    public function __construct(
        private EmailSignatureTemplateRepository $templateRepository,
        private EmailSignatureVariantRepository $variantRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws EmailSignatureTemplateNotFound
     */
    public function __invoke(AddEmailSignatureVariant $message): void
    {
        $template = $this->templateRepository->get($message->templateId);
        $now = $this->clock->now();

        $emailSignatureVariant = new EmailSignatureVariant(
            id: $message->variantId,
            template: $template,
            createdAt: $now,
            name: $message->name,
            code: '',
        );

        $this->variantRepository->add($emailSignatureVariant);
    }
}
