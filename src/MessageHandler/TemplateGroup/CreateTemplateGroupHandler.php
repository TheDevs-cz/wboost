<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\TemplateGroup;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\CustomTemplate;
use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\Entity\SocialNetworkTemplate;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Entity\TemplateGroup;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\TemplateGroup\CreateTemplateGroup;
use WBoost\Web\Repository\CustomTemplateCategoryRepository;
use WBoost\Web\Repository\CustomTemplateRepository;
use WBoost\Web\Repository\CustomTemplateVariantRepository;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Repository\SocialNetworkCategoryRepository;
use WBoost\Web\Repository\SocialNetworkTemplateRepository;
use WBoost\Web\Repository\SocialNetworkTemplateVariantRepository;
use WBoost\Web\Repository\TemplateGroupRepository;
use WBoost\Web\Services\Editor\BackgroundLayer;
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
        private SocialNetworkTemplateRepository $socialTemplateRepository,
        private SocialNetworkTemplateVariantRepository $socialVariantRepository,
        private SocialNetworkCategoryRepository $socialCategoryRepository,
        private CustomTemplateRepository $customTemplateRepository,
        private CustomTemplateVariantRepository $customVariantRepository,
        private CustomTemplateCategoryRepository $customCategoryRepository,
        private ProvideIdentity $provideIdentity,
        private ClockInterface $clock,
        private Filesystem $filesystem,
        private CanvasDesignProjector $projector,
        private UploaderHelper $uploaderHelper,
        private BackgroundLayer $backgroundLayer,
    ) {
    }

    /**
     * @throws ProjectNotFound
     */
    public function __invoke(CreateTemplateGroup $message): void
    {
        $project = $this->projectRepository->get($message->projectId);
        $now = $this->clock->now();

        $sourceVariant = null;

        if ($message->sourceSocialVariantId !== null) {
            $sourceVariant = $this->socialVariantRepository->get($message->sourceSocialVariantId);
        } elseif ($message->sourceCustomVariantId !== null) {
            $sourceVariant = $this->customVariantRepository->get($message->sourceCustomVariantId);
        }

        // Seeded variants inherit the source design's background style verbatim
        // (zero conversion risk for old designs); fresh groups are layer-mode.
        $mode = $sourceVariant !== null ? $sourceVariant->backgroundMode : BackgroundMode::Layer;

        $group = new TemplateGroup($message->groupId, $project, $now, $message->name);
        $this->templateGroupRepository->add($group);

        if ($message->socialVariants !== []) {
            $category = $message->socialCategoryId !== null
                ? $this->socialCategoryRepository->get($message->socialCategoryId)
                : null;

            $template = new SocialNetworkTemplate(
                $this->provideIdentity->next(),
                $project,
                $category,
                $now,
                $message->name,
                null,
                $this->socialTemplateRepository->count($project->id),
            );

            $template->assignToGroup($group);
            $this->socialTemplateRepository->add($template);

            foreach ($message->socialVariants as $selection) {
                $variantId = $this->provideIdentity->next();

                $background = $this->resolveBackground("social-networks/$variantId", $selection->backgroundImage, $sourceVariant);

                $variant = new SocialNetworkTemplateVariant(
                    $variantId,
                    $template,
                    $selection->dimension,
                    $background?->path,
                    $now,
                    $mode,
                );

                $this->seedDesign($variant, $sourceVariant, $selection->dimension->width(), $selection->dimension->height(), $background, $mode);
                $variant->assignToGroup($group);
                $this->socialVariantRepository->add($variant);
            }
        }

        if ($message->customVariants !== []) {
            $category = $message->customCategoryId !== null
                ? $this->customCategoryRepository->get($message->customCategoryId)
                : null;

            $template = new CustomTemplate(
                $this->provideIdentity->next(),
                $project,
                $category,
                $now,
                $message->name,
                null,
                $this->customTemplateRepository->count($project->id),
            );

            $template->assignToGroup($group);
            $this->customTemplateRepository->add($template);

            foreach ($message->customVariants as $selection) {
                $variantId = $this->provideIdentity->next();

                $background = $this->resolveBackground("custom-templates/$variantId", $selection->backgroundImage, $sourceVariant);

                $variant = new CustomTemplateVariant(
                    $variantId,
                    $template,
                    $selection->dimension,
                    $background?->path,
                    $now,
                    $mode,
                );

                $this->seedDesign($variant, $sourceVariant, $selection->dimension->width(), $selection->dimension->height(), $background, $mode);
                $variant->assignToGroup($group);
                $this->customVariantRepository->add($variant);
            }
        }
    }

    /**
     * A selection without an uploaded background copies the source variant's
     * background when the group is created from an existing template (each
     * variant owns its file, so changing one later never affects the others).
     * No upload and no source background → null: the variant starts without a
     * background (layer mode renders it transparent).
     */
    private function resolveBackground(
        string $pathPrefix,
        null|UploadedFile $backgroundImage,
        null|SocialNetworkTemplateVariant|CustomTemplateVariant $sourceVariant,
    ): null|StoredBackgroundImage {
        if ($backgroundImage !== null) {
            $bytes = $backgroundImage->getContent();
            $extension = $backgroundImage->guessExtension();
        } else {
            $sourcePath = $sourceVariant?->backgroundImage;

            if ($sourcePath === null) {
                return null;
            }

            $bytes = $this->filesystem->read($sourcePath);
            $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);
            $extension = $extension !== '' ? $extension : 'png';
        }

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
        SocialNetworkTemplateVariant|CustomTemplateVariant $variant,
        null|SocialNetworkTemplateVariant|CustomTemplateVariant $sourceVariant,
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
