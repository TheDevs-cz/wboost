<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\CustomTemplate;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\CustomTemplateCategoryNotFound;
use WBoost\Web\Exceptions\CustomTemplateNotFound;
use WBoost\Web\Message\CustomTemplate\EditCustomTemplate;
use WBoost\Web\Repository\CustomTemplateCategoryRepository;
use WBoost\Web\Repository\CustomTemplateRepository;

#[AsMessageHandler]
readonly final class EditCustomTemplateHandler
{
    public function __construct(
        private CustomTemplateRepository $customTemplateRepository,
        private CustomTemplateCategoryRepository $customTemplateCategoryRepository,
        private ClockInterface $clock,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @throws CustomTemplateNotFound
     * @throws CustomTemplateCategoryNotFound
     */
    public function __invoke(EditCustomTemplate $message): void
    {
        $template = $this->customTemplateRepository->get($message->templateId);

        $imagePath = $template->image;
        $image = $message->image;

        if ($image !== null) {
            $timestamp = $this->clock->now()->getTimestamp();

            $extension = $image->guessExtension();
            $imagePath = "custom-templates/templates/$message->templateId/image-$timestamp.$extension";
            $this->filesystem->write($imagePath, $image->getContent());
        }

        $category = $message->categoryId !== null
            ? $this->customTemplateCategoryRepository->get($message->categoryId)
            : null;

        $template->edit($category, $message->name, $imagePath);
    }
}
