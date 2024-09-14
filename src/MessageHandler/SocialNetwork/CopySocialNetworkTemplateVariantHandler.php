<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\SocialNetwork;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Exceptions\SocialNetworkTemplateVariantNotFound;
use WBoost\Web\Message\SocialNetwork\CopySocialNetworkTemplateVariant;
use WBoost\Web\Repository\SocialNetworkTemplateVariantRepository;

#[AsMessageHandler]
readonly final class CopySocialNetworkTemplateVariantHandler
{
    public function __construct(
        private SocialNetworkTemplateVariantRepository $variantRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws SocialNetworkTemplateVariantNotFound
     */
    public function __invoke(CopySocialNetworkTemplateVariant $message): void
    {
        $originalVariant = $this->variantRepository->get($message->originalVariantId);

        $variant = new SocialNetworkTemplateVariant(
            $message->newVariantId,
            $originalVariant->template,
            $message->dimension,
            $originalVariant->backgroundImage,
            $this->clock->now(),
        );

        $variant->editCanvas(
            $originalVariant->canvas,
            $originalVariant->inputs,
            $originalVariant->previewImage ?? $originalVariant->backgroundImage,
        );

        $this->variantRepository->add($variant);
    }
}
