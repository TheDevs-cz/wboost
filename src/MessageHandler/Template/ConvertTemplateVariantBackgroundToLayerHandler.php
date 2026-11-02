<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Exceptions\TemplateVariantNotFound;
use WBoost\Web\Message\Template\ConvertTemplateVariantBackgroundToLayer;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\LegacyBackgroundToLayer;
use WBoost\Web\Services\Editor\TemplateVariantImageRenderer;
use WBoost\Web\Value\BackgroundMode;

#[AsMessageHandler]
readonly final class ConvertTemplateVariantBackgroundToLayerHandler
{
    public function __construct(
        private TemplateVariantRepository $variantRepository,
        #[Autowire(service: 'oneup_flysystem.minio_filesystem')]
        private FilesystemOperator $filesystem,
        private UploaderHelper $uploaderHelper,
        private LegacyBackgroundToLayer $converter,
        #[Autowire(service: 'cache.gotenberg_preview')]
        private TagAwareCacheInterface $previewCache,
    ) {
    }

    /**
     * @throws TemplateVariantNotFound
     */
    public function __invoke(ConvertTemplateVariantBackgroundToLayer $message): void
    {
        $variant = $this->variantRepository->get($message->variantId);
        $backgroundPath = $variant->backgroundImage;

        // Already converted, or nothing to convert: a canvas-mode variant
        // without a background renders on a white page, a layer-mode one
        // would turn transparent — leave those alone.
        if ($variant->backgroundMode === BackgroundMode::Layer || $backgroundPath === null || $backgroundPath === '') {
            return;
        }

        try {
            $size = getimagesizefromstring($this->filesystem->read($backgroundPath));
        } catch (FilesystemException) {
            $size = false;
        }

        $variant->convertBackgroundToLayer($this->converter->convert(
            $variant->canvas,
            $backgroundPath,
            $this->uploaderHelper->getPublicPath($backgroundPath),
            is_array($size) ? $size[0] : null,
            is_array($size) ? $size[1] : null,
            $variant->dimension->width(),
            $variant->dimension->height(),
            $message->backgroundInputId,
            $variant->dimension->bleedPixels(),
        ));

        // Housekeeping only (the slice cache key hashes the canvas).
        $this->previewCache->invalidateTags([TemplateVariantImageRenderer::variantCacheTag($variant->id)]);
    }
}
