<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\CustomTemplate;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\Exceptions\CustomTemplateVariantNotFound;
use WBoost\Web\Message\CustomTemplate\CopyCustomTemplateVariant;
use WBoost\Web\Repository\CustomTemplateVariantRepository;

#[AsMessageHandler]
readonly final class CopyCustomTemplateVariantHandler
{
    public function __construct(
        private CustomTemplateVariantRepository $variantRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CustomTemplateVariantNotFound
     */
    public function __invoke(CopyCustomTemplateVariant $message): void
    {
        $originalVariant = $this->variantRepository->get($message->originalVariantId);

        $variant = new CustomTemplateVariant(
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
