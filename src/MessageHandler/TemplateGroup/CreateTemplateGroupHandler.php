<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\TemplateGroup;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Entity\TemplateGroup;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\TemplateGroup\CreateTemplateGroup;
use WBoost\Web\Repository\TemplateCategoryRepository;
use WBoost\Web\Repository\TemplateRepository;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Repository\TemplateGroupRepository;
use WBoost\Web\Services\Editor\BackgroundLayer;
use WBoost\Web\Services\Editor\ResolveGalleryBackground;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\TemplateGroup\CanvasDesignProjector;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\BackgroundMode;
use WBoost\Web\Value\StoredBackgroundImage;

#[AsMessageHandler]
readonly final class CreateTemplateGroupHandler
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private TemplateGroupRepository $templateGroupRepository,
        private TemplateRepository $templateRepository,
        private TemplateVariantRepository $variantRepository,
        private TemplateCategoryRepository $categoryRepository,
        private ProvideIdentity $provideIdentity,
        private ClockInterface $clock,
        private Filesystem $filesystem,
        private CanvasDesignProjector $projector,
        private UploaderHelper $uploaderHelper,
        private BackgroundLayer $backgroundLayer,
        private ResolveGalleryBackground $resolveGalleryBackground,
    ) {
    }

    /**
     * @throws ProjectNotFound
     */
    public function __invoke(CreateTemplateGroup $message): void
    {
        $project = $this->projectRepository->get($message->projectId);
        $now = $this->clock->now();

        $sourceVariant = $message->sourceVariantId !== null
            ? $this->variantRepository->get($message->sourceVariantId)
            : null;

        // Seeded variants inherit the source design's background style verbatim
        // (zero conversion risk for old designs); fresh groups are layer-mode.
        $mode = $sourceVariant !== null ? $sourceVariant->backgroundMode : BackgroundMode::Layer;

        $group = new TemplateGroup($message->groupId, $project, $now, $message->name);
        $this->templateGroupRepository->add($group);

        if ($message->variants === []) {
            return;
        }

        $category = $message->categoryId !== null
            ? $this->categoryRepository->get($message->categoryId)
            : null;

        $template = new Template(
            $this->provideIdentity->next(),
            $project,
            $category,
            $now,
            $message->name,
            null,
            $this->templateRepository->count($project->id),
        );

        $template->assignToGroup($group);
        $this->templateRepository->add($template);

        foreach ($message->variants as $selection) {
            $variantId = $this->provideIdentity->next();

            $background = $this->resolveBackground("custom-templates/$variantId", $selection->backgroundImageId, $project->id, $sourceVariant);

            $variant = new TemplateVariant(
                $variantId,
                $template,
                $selection->dimension,
                $background?->path,
                $now,
                $mode,
            );

            $this->seedDesign($variant, $sourceVariant, $selection->dimension->width(), $selection->dimension->height(), $background, $mode);
            $variant->assignToGroup($group);
            $this->variantRepository->add($variant);
        }
    }

    /**
     * A selection with a picked gallery background REFERENCES the gallery
     * file's path (no copy — the same shape the editor's "Pozadí" pick
     * writes; the cover fit is still computed per dimension). Without a pick,
     * a group created from an existing template copies the source variant's
     * background bytes per variant (each variant owns its file, so changing
     * one later never affects the others). No pick and no source background
     * → null: the variant starts without a background (layer mode renders it
     * transparent).
     */
    private function resolveBackground(
        string $pathPrefix,
        null|string $backgroundImageId,
        UuidInterface $projectId,
        null|TemplateVariant $sourceVariant,
    ): null|StoredBackgroundImage {
        $picked = $this->resolveGalleryBackground->resolve($backgroundImageId, $projectId);

        if ($picked !== null) {
            return $picked;
        }

        $sourcePath = $sourceVariant?->backgroundImage;

        if ($sourcePath === null) {
            return null;
        }

        $bytes = $this->filesystem->read($sourcePath);
        $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);
        $extension = $extension !== '' ? $extension : 'png';

        $timestamp = $this->clock->now()->getTimestamp();
        $backgroundImagePath = "$pathPrefix/background-$timestamp.$extension";
        $this->filesystem->write($backgroundImagePath, $bytes);

        $size = getimagesizefromstring($bytes);

        return new StoredBackgroundImage(
            $backgroundImagePath,
            is_array($size) ? $size[0] : null,
            is_array($size) ? $size[1] : null,
        );
    }

    /**
     * Seeds a freshly created group variant with the source design projected
     * into its own dimension. Inputs and image inputs are copied verbatim
     * (readonly value objects, shared inputIds = the group join key).
     */
    private function seedDesign(
        TemplateVariant $variant,
        null|TemplateVariant $sourceVariant,
        int $targetWidth,
        int $targetHeight,
        null|StoredBackgroundImage $background,
        BackgroundMode $mode,
    ): void {
        if ($sourceVariant === null) {
            // Fresh group (no source design): a layer-mode variant with an
            // uploaded background still needs its layer seeded — nothing
            // synthesizes it at render time.
            if ($mode === BackgroundMode::Layer && $background !== null) {
                $variant->editCanvas(
                    $this->backgroundLayer->applyToCanvas('{}', $this->backgroundLayer->buildObject(
                        $this->uploaderHelper->getPublicPath($background->path),
                        $background->path,
                        $background->naturalWidth,
                        $background->naturalHeight,
                        $targetWidth,
                        $targetHeight,
                    )),
                    [],
                    null,
                );
            }

            return;
        }

        $variant->editCanvas(
            $this->projector->project(
                $sourceVariant->canvas,
                $sourceVariant->dimension->width(),
                $sourceVariant->dimension->height(),
                $targetWidth,
                $targetHeight,
                $background !== null ? $this->uploaderHelper->getPublicPath($background->path) : null,
                $background?->naturalWidth,
                $background?->naturalHeight,
                $mode,
                $background?->path,
            ),
            $sourceVariant->inputs,
            null,
            $sourceVariant->imageInputs,
        );
    }
}
