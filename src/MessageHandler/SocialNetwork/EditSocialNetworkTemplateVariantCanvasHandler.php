<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\SocialNetwork;

use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\SocialNetworkTemplateVariantNotFound;
use WBoost\Web\Message\SocialNetwork\EditSocialNetworkTemplateVariantCanvasEditor;
use WBoost\Web\Repository\SocialNetworkTemplateVariantRepository;

#[AsMessageHandler]
readonly final class EditSocialNetworkTemplateVariantCanvasHandler
{
    public function __construct(
        private SocialNetworkTemplateVariantRepository $variantRepository,
        #[Autowire(service: 'oneup_flysystem.minio_filesystem')]
        private FilesystemOperator $filesystem,
    ) {
    }

    /**
     * @throws SocialNetworkTemplateVariantNotFound
     */
    public function __invoke(EditSocialNetworkTemplateVariantCanvasEditor $message): void
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

        $path = sprintf('social-networks/preview/%s.png', $variantId);
        $this->filesystem->write($path, $binary);

        return $path;
    }
}
