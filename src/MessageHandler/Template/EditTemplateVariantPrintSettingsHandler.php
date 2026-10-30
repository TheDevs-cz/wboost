<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\TemplateVariantNotFound;
use WBoost\Web\Message\Template\EditTemplateVariantPrintSettings;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\BackgroundLayer;

#[AsMessageHandler]
readonly final class EditTemplateVariantPrintSettingsHandler
{
    public function __construct(
        private TemplateVariantRepository $variantRepository,
        private BackgroundLayer $backgroundLayer,
    ) {
    }

    /**
     * @throws TemplateVariantNotFound
     */
    public function __invoke(EditTemplateVariantPrintSettings $message): void
    {
        $variant = $this->variantRepository->get($message->variantId);
        $oldBleed = $variant->dimension->bleedPixels();

        $variant->changePrintSettings($message->bleedMm, $message->cropMarks);

        // The background layer covers the bleed box — follow the new bleed
        // (unless the designer placed it by hand; see recoverForBleed).
        $canvas = $this->backgroundLayer->recoverForBleed(
            $variant->canvas,
            $variant->dimension->width(),
            $variant->dimension->height(),
            $oldBleed,
            $variant->dimension->bleedPixels(),
        );

        if ($canvas !== null) {
            $variant->editCanvas($canvas, $variant->inputs, $variant->previewImagePath, $variant->imageInputs);
        }
    }
}
