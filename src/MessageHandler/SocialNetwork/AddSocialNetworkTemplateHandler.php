<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\SocialNetwork;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\SocialNetworkTemplate;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Exceptions\SocialNetworkCategoryNotFound;
use WBoost\Web\Message\SocialNetwork\AddSocialNetworkTemplate;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Repository\SocialNetworkCategoryRepository;
use WBoost\Web\Repository\SocialNetworkTemplateRepository;

#[AsMessageHandler]
readonly final class AddSocialNetworkTemplateHandler
{
    public function __construct(
        private SocialNetworkTemplateRepository $socialNetworkTemplateRepository,
        private SocialNetworkCategoryRepository $socialNetworkCategoryRepository,
        private ProjectRepository $projectRepository,
        private ClockInterface $clock,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @throws ProjectNotFound
     * @throws SocialNetworkCategoryNotFound
     */
    public function __invoke(AddSocialNetworkTemplate $message): void
    {
        $project = $this->projectRepository->get($message->projectId);
        $templateId = $message->templateId;

        $imagePath = null;
        $image = $message->image;

        if ($image !== null) {
            $timestamp = $this->clock->now()->getTimestamp();

            $extension = $image->guessExtension();
            $imagePath = "social-networks/templates/$message->templateId/image-$timestamp.$extension";
            $this->filesystem->write($imagePath, $image->getContent());
        }

        $nextPosition = $this->socialNetworkTemplateRepository->count($project->id);
        $category = $message->categoryId !== null
            ? $this->socialNetworkCategoryRepository->get($message->categoryId)
            : null;

        $template = new SocialNetworkTemplate(
            $templateId,
            $project,
            $category,
            $this->clock->now(),
            $message->name,
            $imagePath,
            $nextPosition,
        );

        $this->socialNetworkTemplateRepository->add($template);
    }
}
