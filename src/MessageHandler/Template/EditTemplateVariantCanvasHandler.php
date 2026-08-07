<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use League\Flysystem\FilesystemOperator;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use WBoost\Web\Exceptions\TemplateVariantNotFound;
use WBoost\Web\Message\Template\EditTemplateVariantCanvasEditor;
use WBoost\Web\Message\Template\StoreTemplateVariantPreviewImage;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\BackgroundLayer;
use WBoost\Web\Services\Editor\TemplateVariantImageRenderer;
use WBoost\Web\Value\BackgroundMode;

#[AsMessageHandler]
readonly final class EditTemplateVariantCanvasHandler
{
    public function __construct(
        private TemplateVariantRepository $variantRepository,
        #[Autowire(service: 'oneup_flysystem.minio_filesystem')]
        private FilesystemOperator $filesystem,
        private BackgroundLayer $backgroundLayer,
        #[Autowire(service: 'cache.gotenberg_preview')]
        private TagAwareCacheInterface $previewCache,
    ) {
    }

    /**
     * @throws TemplateVariantNotFound
     */
    public function __invoke(EditTemplateVariantCanvasEditor $message): void
    {
        $variant = $this->variantRepository->get($message->variantId);

        // An empty preview (the client couldn't export a tainted canvas) must
        // not wipe the existing thumbnail — keep whatever is already stored.
        $previewImagePath = $message->previewImageDataUri === ''
            ? $variant->previewImagePath
            : $this->persistPreviewImage($message->variantId, $message->previewImageDataUri);

        $variant->editCanvas($message->canvas, $message->inputs, $previewImagePath, $message->imageInputs);

        if ($variant->backgroundMode === BackgroundMode::Layer) {
            // The canvas document is the layer-mode source of truth; keep the
            // denormalized background_image pointer (thumbnail fallback, API
            // backgroundImageUrl) in sync — null when the designer removed
            // the background layer.
            $variant->edit($this->backgroundLayer->extractAssetPath($message->canvas));
        }

        // Drop this variant's cached slice renders. The cache key already
        // hashes the canvas, so a stale hit is not actually reachable — this is
        // housekeeping, not correctness: it stops superseded designs occupying
        // Redis until their TTL runs out, which matters because an admin
        // editing a canvas saves repeatedly.
        $this->previewCache->invalidateTags([TemplateVariantImageRenderer::variantCacheTag($message->variantId)]);
    }

    /**
     * Decodes a `data:image/png;base64,...` URI and writes it as an object in
     * the upload (Minio) filesystem. Returns the storage path, or null if the
     * client supplied no preview (or an unrecognized payload).
     */
    private function persistPreviewImage(UuidInterface $variantId, string $dataUri): null|string
    {
        if ($dataUri === '') {
            return null;
        }

        // Expect: data:image/png;base64,XXXXX (we only ever produce PNG client-side).
        if (!str_starts_with($dataUri, 'data:')) {
            return null;
        }

        $commaPosition = strpos($dataUri, ',');
        if ($commaPosition === false) {
            return null;
        }

        $payload = substr($dataUri, $commaPosition + 1);
        $binary = base64_decode($payload, true);
        if ($binary === false) {
            return null;
        }

        // The key is spelled once, on the message that also exists to write it
        // server-side — two `sprintf()`s of one storage key would drift, and a
        // variant with two thumbnails would keep pointing at the stale one.
        $path = StoreTemplateVariantPreviewImage::pathFor($variantId);
        $this->filesystem->write($path, $binary);

        return $path;
    }
}
