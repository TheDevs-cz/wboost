<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\TemplateVariantNotFound;
use WBoost\Web\Message\Template\CopyTemplateVariant;
use WBoost\Web\Repository\TemplateVariantRepository;

#[AsMessageHandler]
readonly final class CopyTemplateVariantHandler
{
    public function __construct(
        private TemplateVariantRepository $variantRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws TemplateVariantNotFound
     */
    public function __invoke(CopyTemplateVariant $message): void
    {
        $originalVariant = $this->variantRepository->get($message->originalVariantId);

        $variant = new TemplateVariant(
            $message->newVariantId,
            $originalVariant->template,
            $originalVariant->dimension,
            $originalVariant->backgroundImage,
            $this->clock->now(),
            $originalVariant->backgroundMode,
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
