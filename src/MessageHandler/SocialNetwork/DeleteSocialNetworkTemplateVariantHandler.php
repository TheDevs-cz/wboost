<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\SocialNetwork;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\SocialNetworkTemplateVariantNotFound;
use WBoost\Web\Message\SocialNetwork\DeleteSocialNetworkTemplateVariant;
use WBoost\Web\Repository\SocialNetworkTemplateVariantRepository;

#[AsMessageHandler]
readonly final class DeleteSocialNetworkTemplateVariantHandler
{
    public function __construct(
        private SocialNetworkTemplateVariantRepository $variantRepository,
    ) {
    }

    /**
     * @throws SocialNetworkTemplateVariantNotFound
     */
    public function __invoke(DeleteSocialNetworkTemplateVariant $message): void
    {
        $variant = $this->variantRepository->get($message->variantId);

        $this->variantRepository->remove($variant);
    }
}
