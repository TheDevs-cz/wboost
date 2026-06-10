<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Flyer;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\FlyerCategoryNotFound;
use WBoost\Web\Exceptions\FlyerTemplateNotFound;
use WBoost\Web\Message\Flyer\EditFlyerTemplate;
use WBoost\Web\Repository\FlyerCategoryRepository;
use WBoost\Web\Repository\FlyerTemplateRepository;

#[AsMessageHandler]
readonly final class EditFlyerTemplateHandler
{
    public function __construct(
        private FlyerTemplateRepository $flyerTemplateRepository,
        private FlyerCategoryRepository $flyerCategoryRepository,
        private ClockInterface $clock,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @throws FlyerTemplateNotFound
     * @throws FlyerCategoryNotFound
     */
    public function __invoke(EditFlyerTemplate $message): void
    {
        $template = $this->flyerTemplateRepository->get($message->templateId);

        $imagePath = $template->image;
        $image = $message->image;

        if ($image !== null) {
            $timestamp = $this->clock->now()->getTimestamp();

            $extension = $image->guessExtension();
            $imagePath = "flyers/templates/$message->templateId/image-$timestamp.$extension";
            $this->filesystem->write($imagePath, $image->getContent());
        }

        $category = $message->categoryId !== null
            ? $this->flyerCategoryRepository->get($message->categoryId)
            : null;

        $template->edit($category, $message->name, $imagePath);
    }
}
