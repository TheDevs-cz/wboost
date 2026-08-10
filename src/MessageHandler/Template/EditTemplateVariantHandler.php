<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\TemplateVariantNotFound;
use WBoost\Web\Message\Template\EditTemplateVariant;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\BackgroundLayer;
use WBoost\Web\Services\Editor\ResolveGalleryBackground;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\BackgroundMode;

#[AsMessageHandler]
readonly final class EditTemplateVariantHandler
{
    public function __construct(
        private TemplateVariantRepository $variantRepository,
        private Filesystem $filesystem,
        private BackgroundLayer $backgroundLayer,
        private ResolveGalleryBackground $resolveGalleryBackground,
        private UploaderHelper $uploaderHelper,
    ) {
    }

    /**
     * @throws TemplateVariantNotFound
     */
    public function __invoke(EditTemplateVariant $message): void
    {
        $variant = $this->variantRepository->get($message->variantId);

        $newBackgroundImagePath = null;

        // Gallery pick by id (the edit form's picker): resolve into the
        // gallery file's path — project + trash guarded; an id that fails to
        // resolve degrades to "no change".
        $background = $this->resolveGalleryBackground->resolve(
            $message->backgroundImageId,
            $variant->template->project->id,
        );

        if ($background !== null) {
            $newBackgroundImagePath = $background->path;
        } elseif ($message->backgroundImagePath !== null) {
            // Gallery path posted directly by the editor's "Pozadí" pick;
            // just point the variant at it.
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
                $bytes = $this->filesystem->read($newBackgroundImagePath);
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
