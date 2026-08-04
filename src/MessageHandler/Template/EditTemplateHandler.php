<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\TemplateCategoryNotFound;
use WBoost\Web\Exceptions\TemplateNotFound;
use WBoost\Web\Message\Template\EditTemplate;
use WBoost\Web\Repository\TemplateCategoryRepository;
use WBoost\Web\Repository\TemplateRepository;

#[AsMessageHandler]
readonly final class EditTemplateHandler
{
    public function __construct(
        private TemplateRepository $templateRepository,
        private TemplateCategoryRepository $templateCategoryRepository,
        private ClockInterface $clock,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @throws TemplateNotFound
     * @throws TemplateCategoryNotFound
     */
    public function __invoke(EditTemplate $message): void
    {
        $template = $this->templateRepository->get($message->templateId);

        $imagePath = $template->image;
        $image = $message->image;

        if ($image !== null) {
            $timestamp = $this->clock->now()->getTimestamp();

            $extension = $image->guessExtension();
            $imagePath = "custom-templates/templates/$message->templateId/image-$timestamp.$extension";
            $this->filesystem->write($imagePath, $image->getContent());
        }

        $category = $message->categoryId !== null
            ? $this->templateCategoryRepository->get($message->categoryId)
            : null;

        $template->edit($category, $message->name, $imagePath);
    }
}
