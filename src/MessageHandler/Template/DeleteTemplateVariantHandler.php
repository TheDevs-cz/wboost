<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\TemplateVariantNotFound;
use WBoost\Web\Message\Template\DeleteTemplateVariant;
use WBoost\Web\Repository\TemplateVariantRepository;

#[AsMessageHandler]
readonly final class DeleteTemplateVariantHandler
{
    public function __construct(
        private TemplateVariantRepository $variantRepository,
    ) {
    }

    /**
     * @throws TemplateVariantNotFound
     */
    public function __invoke(DeleteTemplateVariant $message): void
    {
        $variant = $this->variantRepository->get($message->variantId);

        $this->variantRepository->remove($variant);
    }
}
