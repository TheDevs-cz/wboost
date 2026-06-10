<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Flyer;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\FlyerTemplateVariant;
use WBoost\Web\Exceptions\FlyerTemplateVariantNotFound;
use WBoost\Web\Message\Flyer\CopyFlyerTemplateVariant;
use WBoost\Web\Repository\FlyerTemplateVariantRepository;

#[AsMessageHandler]
readonly final class CopyFlyerTemplateVariantHandler
{
    public function __construct(
        private FlyerTemplateVariantRepository $variantRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws FlyerTemplateVariantNotFound
     */
    public function __invoke(CopyFlyerTemplateVariant $message): void
    {
        $originalVariant = $this->variantRepository->get($message->originalVariantId);

        $variant = new FlyerTemplateVariant(
            $message->newVariantId,
            $originalVariant->template,
            $originalVariant->dimension,
            $originalVariant->backgroundImage,
            $this->clock->now(),
        );

        $variant->editCanvas(
            $originalVariant->canvas,
            $originalVariant->inputs,
            $originalVariant->previewImagePath,
            $originalVariant->imageInputs,
        );

        $this->variantRepository->add($variant);
    }
}
