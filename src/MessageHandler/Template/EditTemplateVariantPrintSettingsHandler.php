<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\TemplateVariantNotFound;
use WBoost\Web\Message\Template\EditTemplateVariantPrintSettings;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Template\ApplyPrintSettings;

#[AsMessageHandler]
readonly final class EditTemplateVariantPrintSettingsHandler
{
    public function __construct(
        private TemplateVariantRepository $variantRepository,
        private ApplyPrintSettings $applyPrintSettings,
    ) {
    }

    /**
     * @throws TemplateVariantNotFound
     */
    public function __invoke(EditTemplateVariantPrintSettings $message): void
    {
        ($this->applyPrintSettings)($this->variantRepository->get($message->variantId), $message->bleedMm, $message->cropMarks);
    }
}
