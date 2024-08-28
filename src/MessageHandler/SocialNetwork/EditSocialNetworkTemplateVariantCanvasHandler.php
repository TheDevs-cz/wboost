<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\SocialNetwork;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\SocialNetworkTemplateVariantNotFound;
use WBoost\Web\Message\SocialNetwork\EditSocialNetworkTemplateVariantCanvasEditor;
use WBoost\Web\Repository\SocialNetworkTemplateVariantRepository;

#[AsMessageHandler]
readonly final class EditSocialNetworkTemplateVariantCanvasHandler
{
    public function __construct(
        private SocialNetworkTemplateVariantRepository $variantRepository,
    ) {
    }

    /**
     * @throws SocialNetworkTemplateVariantNotFound
     */
    public function __invoke(EditSocialNetworkTemplateVariantCanvasEditor $message): void
    {
        $variant = $this->variantRepository->get($message->variantId);

        $variant->editCanvas($message->canvas, $message->inputs);
    }
}
