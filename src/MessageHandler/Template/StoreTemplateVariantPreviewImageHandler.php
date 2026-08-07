<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\TemplateVariantNotFound;
use WBoost\Web\Message\Template\StoreTemplateVariantPreviewImage;
use WBoost\Web\Repository\TemplateVariantRepository;

/**
 * Writes the thumbnail object and points the row at it — nothing else.
 *
 * The object key is `custom-templates/preview/{variantId}.png`, i.e. one object
 * per variant, OVERWRITTEN on every save. That is the pre-existing convention
 * (`EditTemplateVariantCanvasHandler` has always written that exact key) and it
 * is why re-designing a variant a hundred times does not leave a hundred
 * abandoned objects in the bucket the way every timestamped `Edit*` upload in
 * this app does. Deliberately kept.
 *
 * `previewImagePath` is set through {@see \WBoost\Web\Entity\TemplateVariant::editPreviewImage()}
 * rather than through `editCanvas()`: the canvas is not this handler's business
 * (plan §4.5-20 — canvas writes go through `EditTemplateVariantCanvasEditor`
 * and only there), and re-passing the current canvas just to reach the
 * thumbnail field would make this handler look like a second canvas writer.
 */
#[AsMessageHandler]
readonly final class StoreTemplateVariantPreviewImageHandler
{
    public function __construct(
        private TemplateVariantRepository $variantRepository,
        #[Autowire(service: 'oneup_flysystem.minio_filesystem')]
        private FilesystemOperator $filesystem,
    ) {
    }

    /**
     * @throws TemplateVariantNotFound
     */
    public function __invoke(StoreTemplateVariantPreviewImage $message): void
    {
        $variant = $this->variantRepository->get($message->variantId);

        if ($message->imageBytes === '') {
            // Nothing to store, and — mirroring the browser path's empty
            // `previewImageDataUri` — an absent picture must never wipe the
            // thumbnail a previous save produced.
            return;
        }

        $path = StoreTemplateVariantPreviewImage::pathFor($message->variantId);

        $this->filesystem->write($path, $message->imageBytes);

        $variant->editPreviewImage($path);
    }
}
