<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\CustomTemplate;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\CustomTemplateVariantNotFound;
use WBoost\Web\Message\CustomTemplate\EditCustomTemplateVariant;
use WBoost\Web\Repository\CustomTemplateVariantRepository;
use WBoost\Web\Services\Editor\BackgroundLayer;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\BackgroundMode;

#[AsMessageHandler]
readonly final class EditCustomTemplateVariantHandler
{
    public function __construct(
        private CustomTemplateVariantRepository $variantRepository,
        private ClockInterface $clock,
        private Filesystem $filesystem,
        private BackgroundLayer $backgroundLayer,
        private UploaderHelper $uploaderHelper,
    ) {
    }

    /**
     * @throws CustomTemplateVariantNotFound
     */
    public function __invoke(EditCustomTemplateVariant $message): void
    {
        $variant = $this->variantRepository->get($message->variantId);

        $backgroundImage = $message->backgroundImage;
        $newBackgroundImagePath = null;
        $bytes = null;

        if ($backgroundImage !== null) {
            // Raw-upload path: store the file alongside the variant.
            $timestamp = $this->clock->now()->getTimestamp();

            $extension = $backgroundImage->guessExtension();
            $newBackgroundImagePath = "custom-templates/$variant->id/background-$timestamp.$extension";
            $bytes = $backgroundImage->getContent();
            $this->filesystem->write($newBackgroundImagePath, $bytes);
        } elseif ($message->backgroundImagePath !== null) {
            // Gallery path: the asset already lives in S3/Minio under
            // file-upload/{projectId}/{fileId}.{ext} as a FileUpload row; just
            // point the variant at it.
            $newBackgroundImagePath = $message->backgroundImagePath;
        }

        // Empty input never removes an existing background.
        if ($newBackgroundImagePath === null) {
            return;
        }

        if ($variant->backgroundMode === BackgroundMode::Layer) {
            // Layer mode: the background lives in the canvas document — swap
            // the layer's picture in place (stack index + input metadata are
            // preserved by the helper), re-cover-fitted top-left.
            try {
                $bytes ??= $this->filesystem->read($newBackgroundImagePath);
            } catch (FilesystemException) {
                $bytes = null;
            }

            $size = $bytes !== null ? getimagesizefromstring($bytes) : false;

            $variant->editCanvas(
                $this->backgroundLayer->applyToCanvas($variant->canvas, $this->backgroundLayer->buildObject(
                    $this->uploaderHelper->getPublicPath($newBackgroundImagePath),
                    $newBackgroundImagePath,
                    is_array($size) ? $size[0] : null,
                    is_array($size) ? $size[1] : null,
                    $variant->dimension->width(),
                    $variant->dimension->height(),
                )),
                $variant->inputs,
                $variant->previewImagePath,
                $variant->imageInputs,
            );
        }

        $variant->edit($newBackgroundImagePath);
    }
}
