<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\SocialNetwork;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\SocialNetworkCategoryNotFound;
use WBoost\Web\Exceptions\SocialNetworkTemplateNotFound;
use WBoost\Web\Message\SocialNetwork\EditSocialNetworkTemplate;
use WBoost\Web\Repository\SocialNetworkCategoryRepository;
use WBoost\Web\Repository\SocialNetworkTemplateRepository;

#[AsMessageHandler]
readonly final class EditSocialNetworkTemplateHandler
{
    public function __construct(
        private SocialNetworkTemplateRepository $socialNetworkTemplateRepository,
        private SocialNetworkCategoryRepository $socialNetworkCategoryRepository,
        private ClockInterface $clock,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @throws SocialNetworkTemplateNotFound
     * @throws SocialNetworkCategoryNotFound
     */
    public function __invoke(EditSocialNetworkTemplate $message): void
    {
        $template = $this->socialNetworkTemplateRepository->get($message->templateId);

        $imagePath = $template->image;
        $image = $message->image;

        if ($image !== null) {
            $timestamp = $this->clock->now()->getTimestamp();

            $extension = $image->guessExtension();
            $imagePath = "social-networks/templates/$message->templateId/image-$timestamp.$extension";
            $this->filesystem->write($imagePath, $image->getContent());
        }

        $category = $message->categoryId !== null
            ? $this->socialNetworkCategoryRepository->get($message->categoryId)
            : null;

        $template->edit($category, $message->name, $imagePath);
    }
}
