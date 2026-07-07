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
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\TemplateGroup\CanvasDesignProjector;
use WBoost\Web\Services\UploaderHelper;
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
                    $background->path,
                    $now,
                );

                $this->seedDesign($variant, $sourceVariant, $selection->dimension->width(), $selection->dimension->height(), $background);
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
                    $background->path,
                    $now,
                );

                $this->seedDesign($variant, $sourceVariant, $selection->dimension->width(), $selection->dimension->height(), $background);
                $variant->assignToGroup($group);
                $this->customVariantRepository->add($variant);
            }
        }
    }

    /**
     * A selection without an uploaded background is only valid when the group
     * is created from an existing template — the source variant's background
     * is then copied for the new variant (each variant owns its file, so
     * changing one later never affects the others).
     */
    private function resolveBackground(
        string $pathPrefix,
        null|UploadedFile $backgroundImage,
        null|SocialNetworkTemplateVariant|CustomTemplateVariant $sourceVariant,
    ): StoredBackgroundImage {
        if ($backgroundImage !== null) {
            $bytes = $backgroundImage->getContent();
            $extension = $backgroundImage->guessExtension();
        } else {
            if ($sourceVariant === null) {
                throw new \LogicException('Selection without a background requires a source variant.');
            }

            $bytes = $this->filesystem->read($sourceVariant->backgroundImage);
            $extension = pathinfo($sourceVariant->backgroundImage, PATHINFO_EXTENSION);
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
        StoredBackgroundImage $background,
    ): void {
        if ($sourceVariant === null) {
            return;
        }

        $variant->editCanvas(
            $this->projector->project(
                $sourceVariant->canvas,
                $sourceVariant->dimension->width(),
                $sourceVariant->dimension->height(),
                $targetWidth,
                $targetHeight,
                $this->uploaderHelper->getPublicPath($background->path),
                $background->naturalWidth,
                $background->naturalHeight,
            ),
            $sourceVariant->inputs,
            null,
            $sourceVariant->imageInputs,
        );
    }
}
