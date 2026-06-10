<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Flyer;

use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\FlyerTemplateVariantNotFound;
use WBoost\Web\Message\Flyer\EditFlyerTemplateVariantCanvasEditor;
use WBoost\Web\Repository\FlyerTemplateVariantRepository;

#[AsMessageHandler]
readonly final class EditFlyerTemplateVariantCanvasHandler
{
    public function __construct(
        private FlyerTemplateVariantRepository $variantRepository,
        #[Autowire(service: 'oneup_flysystem.minio_filesystem')]
        private FilesystemOperator $filesystem,
    ) {
    }

    /**
     * @throws FlyerTemplateVariantNotFound
     */
    public function __invoke(EditFlyerTemplateVariantCanvasEditor $message): void
    {
        $variant = $this->variantRepository->get($message->variantId);

        // An empty preview (the client couldn't export a tainted canvas) must
        // not wipe the existing thumbnail — keep whatever is already stored.
        $previewImagePath = $message->previewImageDataUri === ''
            ? $variant->previewImagePath
            : $this->persistPreviewImage($message->variantId->toString(), $message->previewImageDataUri);

        $variant->editCanvas($message->canvas, $message->inputs, $previewImagePath, $message->imageInputs);
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

        $path = sprintf('flyers/preview/%s.png', $variantId);
        $this->filesystem->write($path, $binary);

        return $path;
    }
}
