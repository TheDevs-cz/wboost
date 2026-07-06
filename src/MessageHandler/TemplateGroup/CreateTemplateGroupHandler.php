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
    ) {
    }

    /**
     * @throws ProjectNotFound
     */
    public function __invoke(CreateTemplateGroup $message): void
    {
        $project = $this->projectRepository->get($message->projectId);
        $now = $this->clock->now();

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

                $variant = new SocialNetworkTemplateVariant(
                    $variantId,
                    $template,
                    $selection->dimension,
                    $this->writeBackground("social-networks/$variantId", $selection->backgroundImage),
                    $now,
                );

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

                $variant = new CustomTemplateVariant(
                    $variantId,
                    $template,
                    $selection->dimension,
                    $this->writeBackground("custom-templates/$variantId", $selection->backgroundImage),
                    $now,
                );

                $variant->assignToGroup($group);
                $this->customVariantRepository->add($variant);
            }
        }
    }

    private function writeBackground(string $pathPrefix, UploadedFile $backgroundImage): string
    {
        $timestamp = $this->clock->now()->getTimestamp();
        $extension = $backgroundImage->guessExtension();

        $backgroundImagePath = "$pathPrefix/background-$timestamp.$extension";
        $this->filesystem->write($backgroundImagePath, $backgroundImage->getContent());

        return $backgroundImagePath;
    }
}
