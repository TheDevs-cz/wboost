<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\TemplateVariantNotFound;
use WBoost\Web\Message\Template\EditTemplateVariantPrintSettings;
use WBoost\Web\Repository\TemplateVariantRepository;

#[AsMessageHandler]
readonly final class EditTemplateVariantPrintSettingsHandler
{
    public function __construct(
        private TemplateVariantRepository $variantRepository,
    ) {
    }

    /**
     * @throws TemplateVariantNotFound
     */
    public function __invoke(EditTemplateVariantPrintSettings $message): void
    {
        $variant = $this->variantRepository->get($message->variantId);

        $variant->changePrintSettings($message->bleedMm, $message->cropMarks);
    }
}
