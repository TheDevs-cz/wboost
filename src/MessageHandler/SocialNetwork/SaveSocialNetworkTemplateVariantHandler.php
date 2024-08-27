<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\SocialNetwork;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\SocialNetwork\SaveSocialNetworkTemplateVariantEditor;
use WBoost\Web\Repository\SocialNetworkTemplateVariantRepository;

#[AsMessageHandler]
readonly final class SaveSocialNetworkTemplateVariantHandler
{
    public function __construct(
        private SocialNetworkTemplateVariantRepository $variantRepository,
    ) {
    }

    public function __invoke(SaveSocialNetworkTemplateVariantEditor $message): void
    {
        $variant = $this->variantRepository->get($message->variantId);

        $variant->edit($message->canvas, $message->inputs);
    }
}
