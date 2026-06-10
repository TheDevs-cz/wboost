<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Flyer;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\FlyerTemplateVariantNotFound;
use WBoost\Web\Message\Flyer\DeleteFlyerTemplateVariant;
use WBoost\Web\Repository\FlyerTemplateVariantRepository;

#[AsMessageHandler]
readonly final class DeleteFlyerTemplateVariantHandler
{
    public function __construct(
        private FlyerTemplateVariantRepository $variantRepository,
    ) {
    }

    /**
     * @throws FlyerTemplateVariantNotFound
     */
    public function __invoke(DeleteFlyerTemplateVariant $message): void
    {
        $variant = $this->variantRepository->get($message->variantId);

        $this->variantRepository->remove($variant);
    }
}
