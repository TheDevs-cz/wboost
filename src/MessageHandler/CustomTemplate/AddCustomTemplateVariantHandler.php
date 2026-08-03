<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\CustomTemplate;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\Exceptions\CustomTemplateNotFound;
use WBoost\Web\Message\CustomTemplate\AddCustomTemplateVariant;
use WBoost\Web\Repository\CustomTemplateRepository;
use WBoost\Web\Repository\CustomTemplateVariantRepository;
use WBoost\Web\Services\Editor\BackgroundLayer;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\BackgroundMode;

#[AsMessageHandler]
readonly final class AddCustomTemplateVariantHandler
{
    public function __construct(
        private CustomTemplateRepository $templateRepository,
        private CustomTemplateVariantRepository $variantRepository,
        private ClockInterface $clock,
        private Filesystem $filesystem,
        private BackgroundLayer $backgroundLayer,
        private UploaderHelper $uploaderHelper,
    ) {
    }

    /**
     * @throws CustomTemplateNotFound
     */
    public function __invoke(AddCustomTemplateVariant $message): void
    {
        $template = $this->templateRepository->get($message->templateId);
        $variantId = $message->variantId;
        $backgroundImage = $message->backgroundImage;

        // New variants are layer-mode: the background (when provided at all)
        // is a regular canvas object, not the canvas-level backgroundImage.
        $backgroundImagePath = null;
        $canvas = null;

        if ($backgroundImage !== null) {
            $timestamp = $this->clock->now()->getTimestamp();

            $extension = $backgroundImage->guessExtension();
            $backgroundImagePath = "custom-templates/$variantId/background-$timestamp.$extension";
            $bytes = $backgroundImage->getContent();
            $this->filesystem->write($backgroundImagePath, $bytes);

            $size = getimagesizefromstring($bytes);

            $canvas = $this->backgroundLayer->applyToCanvas('{}', $this->backgroundLayer->buildObject(
                $this->uploaderHelper->getPublicPath($backgroundImagePath),
                $backgroundImagePath,
                is_array($size) ? $size[0] : null,
                is_array($size) ? $size[1] : null,
                $message->dimension->width(),
                $message->dimension->height(),
            ));
        }

        $variant = new CustomTemplateVariant(
            $variantId,
            $template,
            $message->dimension,
            $backgroundImagePath,
            $this->clock->now(),
            BackgroundMode::Layer,
        );

        if ($canvas !== null) {
            $variant->editCanvas($canvas, [], null);
        }

        $this->variantRepository->add($variant);
    }
}
