<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\TemplateNotFound;
use WBoost\Web\Message\Template\AddTemplateVariant;
use WBoost\Web\Repository\TemplateRepository;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\BackgroundLayer;
use WBoost\Web\Services\Editor\ResolveGalleryBackground;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\BackgroundMode;

#[AsMessageHandler]
readonly final class AddTemplateVariantHandler
{
    public function __construct(
        private TemplateRepository $templateRepository,
        private TemplateVariantRepository $variantRepository,
        private ClockInterface $clock,
        private BackgroundLayer $backgroundLayer,
        private ResolveGalleryBackground $resolveGalleryBackground,
        private UploaderHelper $uploaderHelper,
    ) {
    }

    /**
     * @throws TemplateNotFound
     */
    public function __invoke(AddTemplateVariant $message): void
    {
        $template = $this->templateRepository->get($message->templateId);
        $variantId = $message->variantId;

        // New variants are layer-mode: the background (when provided at all)
        // is a regular canvas object, not the canvas-level backgroundImage.
        // The picked GALLERY file is referenced by path, never copied — the
        // same shape the editor's "Pozadí" pick writes.
        $backgroundImagePath = null;
        $canvas = null;

        $background = $this->resolveGalleryBackground->resolve($message->backgroundImageId, $template->project->id);

        if ($background !== null) {
            $backgroundImagePath = $background->path;

            $canvas = $this->backgroundLayer->applyToCanvas('{}', $this->backgroundLayer->buildObject(
                $this->uploaderHelper->getPublicPath($backgroundImagePath),
                $backgroundImagePath,
                $background->naturalWidth,
                $background->naturalHeight,
                $message->dimension->width(),
                $message->dimension->height(),
            ));
        }

        $variant = new TemplateVariant(
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
