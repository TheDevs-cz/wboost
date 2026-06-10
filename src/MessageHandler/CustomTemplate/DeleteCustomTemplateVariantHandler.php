<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\CustomTemplate;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\CustomTemplateVariantNotFound;
use WBoost\Web\Message\CustomTemplate\DeleteCustomTemplateVariant;
use WBoost\Web\Repository\CustomTemplateVariantRepository;

#[AsMessageHandler]
readonly final class DeleteCustomTemplateVariantHandler
{
    public function __construct(
        private CustomTemplateVariantRepository $variantRepository,
    ) {
    }

    /**
     * @throws CustomTemplateVariantNotFound
     */
    public function __invoke(DeleteCustomTemplateVariant $message): void
    {
        $variant = $this->variantRepository->get($message->variantId);

        $this->variantRepository->remove($variant);
    }
}
