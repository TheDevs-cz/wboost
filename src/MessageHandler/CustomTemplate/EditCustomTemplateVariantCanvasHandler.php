<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\CustomTemplate;

use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\CustomTemplateVariantNotFound;
use WBoost\Web\Message\CustomTemplate\EditCustomTemplateVariantCanvasEditor;
use WBoost\Web\Repository\CustomTemplateVariantRepository;
use WBoost\Web\Services\Editor\BackgroundLayer;
use WBoost\Web\Value\BackgroundMode;

#[AsMessageHandler]
readonly final class EditCustomTemplateVariantCanvasHandler
{
    public function __construct(
        private CustomTemplateVariantRepository $variantRepository,
        #[Autowire(service: 'oneup_flysystem.minio_filesystem')]
        private FilesystemOperator $filesystem,
        private BackgroundLayer $backgroundLayer,
    ) {
    }

    /**
     * @throws CustomTemplateVariantNotFound
     */
    public function __invoke(EditCustomTemplateVariantCanvasEditor $message): void
    {
        $variant = $this->variantRepository->get($message->variantId);

        // An empty preview (the client couldn't export a tainted canvas) must
        // not wipe the existing thumbnail — keep whatever is already stored.
        $previewImagePath = $message->previewImageDataUri === ''
            ? $variant->previewImagePath
            : $this->persistPreviewImage($message->variantId->toString(), $message->previewImageDataUri);

        $variant->editCanvas($message->canvas, $message->inputs, $previewImagePath, $message->imageInputs);

        if ($variant->backgroundMode === BackgroundMode::Layer) {
            // The canvas document is the layer-mode source of truth; keep the
            // denormalized background_image pointer (thumbnail fallback, API
            // backgroundImageUrl) in sync — null when the designer removed
            // the background layer.
            $variant->edit($this->backgroundLayer->extractAssetPath($message->canvas));
        }
    }

    /**
     * Decodes a `data:image/png;base64,...` URI and writes it as an object in
     * the upload (Minio) filesystem. Returns the storage path, or null if the
     * client supplied no preview (or an unrecognized payload).
     */
    private function persistPreviewImage(string $variantId, string $dataUri): null|string
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

        $path = sprintf('custom-templates/preview/%s.png', $variantId);
        $this->filesystem->write($path, $binary);

        return $path;
    }
}
